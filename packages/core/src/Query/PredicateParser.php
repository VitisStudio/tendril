<?php

namespace VitisStudio\Tendril\Core\Query;

use InvalidArgumentException;
use VitisStudio\Tendril\Core\Exceptions\InvalidPredicate;
use VitisStudio\Tendril\Core\Support\JsonSql;

/**
 * Validates and normalizes predicate trees. Bounded: depth 5, 50 nodes,
 * 100 values per list, 256 characters per string value.
 */
final class PredicateParser
{
    public const array OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'starts_with', 'exists', 'not_exists'];

    public const array COLUMNS = ['name', 'source', 'version'];

    public const int MAX_DEPTH = 5;

    public const int MAX_NODES = 50;

    /**
     * @param  array<mixed>  $tree
     * @return array<string, mixed>
     */
    public static function normalize(array $tree): array
    {
        $count = 0;

        return self::node($tree, 1, '$', $count);
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private static function node(array $node, int $depth, string $at, int &$count): array
    {
        if (++$count > self::MAX_NODES) {
            throw InvalidPredicate::because('too many nodes', $at);
        }

        if ($depth > self::MAX_DEPTH) {
            throw InvalidPredicate::because('too deep', $at);
        }

        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $node)) {
                if (count($node) !== 1 || ! is_array($node[$group]) || ! array_is_list($node[$group])) {
                    throw InvalidPredicate::because("\"{$group}\" must be the only key and hold a list", $at);
                }

                $children = [];

                foreach ($node[$group] as $i => $child) {
                    if (! is_array($child)) {
                        throw InvalidPredicate::because('nodes must be objects', "{$at}.{$group}[{$i}]");
                    }

                    $children[] = self::node($child, $depth + 1, "{$at}.{$group}[{$i}]", $count);
                }

                return [$group => $children];
            }
        }

        if (array_key_exists('not', $node)) {
            if (count($node) !== 1 || ! is_array($node['not'])) {
                throw InvalidPredicate::because('"not" must be the only key and hold a node', $at);
            }

            return ['not' => self::node($node['not'], $depth + 1, "{$at}.not", $count)];
        }

        return self::condition($node, $at);
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    private static function condition(array $node, string $at): array
    {
        $field = $node['field'] ?? null;
        $op = $node['op'] ?? null;

        if (! is_string($field) || ! is_string($op)) {
            throw InvalidPredicate::because('conditions need "field" and "op"', $at);
        }

        if (! in_array($op, self::OPERATORS, true)) {
            throw InvalidPredicate::because("unknown operator [{$op}]", $at);
        }

        self::field($field, $at);

        $out = ['field' => $field, 'op' => $op];

        if (in_array($op, ['exists', 'not_exists'], true)) {
            if (in_array($field, self::COLUMNS, true)) {
                throw InvalidPredicate::because('exists applies to properties/context only', $at);
            }

            return $out;
        }

        if (! array_key_exists('value', $node)) {
            throw InvalidPredicate::because('missing "value"', $at);
        }

        $value = $node['value'];

        if (in_array($op, ['in', 'not_in'], true)) {
            if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
                throw InvalidPredicate::because('"in" needs a non-empty list of up to 100 values', $at);
            }

            $strings = array_filter($value, 'is_string');
            $numbers = array_filter($value, static fn ($v) => is_int($v) || is_float($v));

            if (count($strings) !== count($value) && count($numbers) !== count($value)) {
                throw InvalidPredicate::because('"in" lists must be all strings or all numbers', $at);
            }

            foreach ($strings as $string) {
                self::string($string, $at);
            }

            self::columnValue($field, $op, $value, $at);

            $out['value'] = $value;

            return $out;
        }

        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
            if (! is_int($value) && ! is_float($value)) {
                throw InvalidPredicate::because('numeric comparisons need a number', $at);
            }
        } elseif (in_array($op, ['contains', 'starts_with'], true)) {
            if (! is_string($value) || $value === '') {
                throw InvalidPredicate::because("{$op} needs a non-empty string", $at);
            }

            self::string($value, $at);
        } elseif (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            throw InvalidPredicate::because('eq/neq need a string, number or boolean', $at);
        } elseif (is_string($value)) {
            self::string($value, $at);
        }

        self::columnValue($field, $op, $value, $at);

        $out['value'] = $value;

        return $out;
    }

    /**
     * name/source are strings; version is an integer.
     */
    private static function columnValue(string $field, string $op, mixed $value, string $at): void
    {
        if (! in_array($field, self::COLUMNS, true)) {
            return;
        }

        $values = is_array($value) ? $value : [$value];

        if ($field === 'version') {
            if (in_array($op, ['contains', 'starts_with'], true)) {
                throw InvalidPredicate::because('version only supports numeric operators', $at);
            }

            foreach ($values as $v) {
                if (! is_int($v)) {
                    throw InvalidPredicate::because('version comparisons need integers', $at);
                }
            }

            return;
        }

        if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
            throw InvalidPredicate::because("{$field} only supports string operators", $at);
        }

        foreach ($values as $v) {
            if (! is_string($v)) {
                throw InvalidPredicate::because("{$field} comparisons need strings", $at);
            }
        }
    }

    private static function field(string $field, string $at): void
    {
        if (in_array($field, self::COLUMNS, true)) {
            return;
        }

        $parts = explode('.', $field);
        $root = array_shift($parts);

        if (! in_array($root, ['properties', 'context'], true) || $parts === []) {
            throw InvalidPredicate::because("unknown field [{$field}]", $at);
        }

        try {
            JsonSql::assertPath($parts);
        } catch (InvalidArgumentException $e) {
            throw InvalidPredicate::because($e->getMessage(), $at);
        }
    }

    private static function string(string $value, string $at): void
    {
        if (mb_strlen($value) > 256) {
            throw InvalidPredicate::because('string values are limited to 256 characters', $at);
        }
    }
}
