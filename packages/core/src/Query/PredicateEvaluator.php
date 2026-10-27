<?php

namespace VitisStudio\Tendril\Core\Query;

/**
 * In-memory evaluation with exactly the SQL semantics (see Predicate).
 */
final class PredicateEvaluator
{
    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $event
     */
    public static function evaluate(array $node, array $event): bool
    {
        if (isset($node['all']) && is_array($node['all'])) {
            foreach ($node['all'] as $child) {
                if (! self::evaluate((array) $child, $event)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($node['any']) && is_array($node['any'])) {
            foreach ($node['any'] as $child) {
                if (self::evaluate((array) $child, $event)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($node['not']) && is_array($node['not'])) {
            return ! self::evaluate($node['not'], $event);
        }

        return self::condition($node, $event);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $event
     */
    private static function condition(array $node, array $event): bool
    {
        $field = (string) $node['field'];
        $op = (string) $node['op'];
        $expected = $node['value'] ?? null;
        [$found, $actual] = self::resolve($field, $event);

        if ($op === 'exists') {
            return $found;
        }

        if ($op === 'not_exists') {
            return ! $found;
        }

        if (! $found || $actual === null || is_array($actual)) {
            return false;
        }

        $isNumber = is_int($actual) || is_float($actual);
        $isColumn = in_array($field, PredicateParser::COLUMNS, true);

        return match ($op) {
            'eq', 'neq' => self::equals($actual, $expected, $isColumn) === ($op === 'eq') && self::comparable($actual, $expected, $isColumn),
            'gt' => $isNumber && $actual > $expected,
            'gte' => $isNumber && $actual >= $expected,
            'lt' => $isNumber && $actual < $expected,
            'lte' => $isNumber && $actual <= $expected,
            'in', 'not_in' => self::inList($actual, (array) $expected, $isColumn, $op === 'in'),
            'contains' => is_string($actual) && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected)),
            'starts_with' => is_string($actual) && str_starts_with(mb_strtolower($actual), mb_strtolower((string) $expected)),
            default => false,
        };
    }

    /**
     * Whether the operands have comparable types (otherwise both eq and neq are false).
     */
    private static function comparable(mixed $actual, mixed $expected, bool $isColumn): bool
    {
        if ($isColumn) {
            return true;
        }

        if (is_bool($expected)) {
            return is_bool($actual);
        }

        if (is_string($expected)) {
            return is_string($actual);
        }

        return is_int($actual) || is_float($actual);
    }

    private static function equals(mixed $actual, mixed $expected, bool $isColumn): bool
    {
        if ($isColumn) {
            return (string) $actual === (string) $expected;
        }

        if (is_bool($expected)) {
            return $actual === $expected;
        }

        if (is_string($expected)) {
            return $actual === $expected;
        }

        return (is_int($actual) || is_float($actual)) && (float) $actual === (float) $expected;
    }

    /**
     * @param  array<mixed>  $list
     */
    private static function inList(mixed $actual, array $list, bool $isColumn, bool $positive): bool
    {
        $numeric = $list !== [] && (is_int($list[0]) || is_float($list[0]));

        if (! $isColumn && ($numeric ? ! (is_int($actual) || is_float($actual)) : ! is_string($actual))) {
            return false;
        }

        $hit = false;

        foreach ($list as $value) {
            if ($numeric ? (float) $actual === (float) $value : (string) $actual === (string) $value) {
                $hit = true;

                break;
            }
        }

        return $positive ? $hit : ! $hit;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: bool, 1: mixed}
     */
    private static function resolve(string $field, array $event): array
    {
        if (in_array($field, PredicateParser::COLUMNS, true)) {
            return [array_key_exists($field, $event) && $event[$field] !== null, $event[$field] ?? null];
        }

        $parts = explode('.', $field);
        $current = $event[array_shift($parts)] ?? null;

        foreach ($parts as $part) {
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                return [false, null];
            }

            $current = $current[$part];
        }

        return [true, $current];
    }
}
