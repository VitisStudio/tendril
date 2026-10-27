<?php

namespace VitisStudio\Tendril\Core\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Support\JsonSql;

/**
 * Compiles a normalized predicate tree to portable SQL. Every condition is
 * wrapped as CASE WHEN … THEN 1 ELSE 0 END = 1 so NULL never leaks into NOT.
 */
final class PredicateCompiler
{
    private function __construct(
        private readonly Connection $connection,
        private readonly JsonSql $json,
    ) {}

    public static function for(ConnectionInterface $connection): self
    {
        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('Predicate compilation needs an Illuminate\Database\Connection.');
        }

        return new self($connection, JsonSql::for($connection));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: list<mixed>}
     */
    public function compile(array $node, ?string $table = null): array
    {
        if (isset($node['all']) && is_array($node['all'])) {
            return $this->group($node['all'], 'AND', '1 = 1', $table);
        }

        if (isset($node['any']) && is_array($node['any'])) {
            return $this->group($node['any'], 'OR', '1 = 0', $table);
        }

        if (isset($node['not']) && is_array($node['not'])) {
            [$sql, $bindings] = $this->compile($node['not'], $table);

            return ["NOT ({$sql})", $bindings];
        }

        [$sql, $bindings] = $this->condition($node, $table);

        return ["(CASE WHEN {$sql} THEN 1 ELSE 0 END) = 1", $bindings];
    }

    /**
     * @param  array<mixed>  $children
     * @return array{0: string, 1: list<mixed>}
     */
    private function group(array $children, string $glue, string $empty, ?string $table): array
    {
        if ($children === []) {
            return [$empty, []];
        }

        $parts = [];
        $bindings = [];

        foreach ($children as $child) {
            [$sql, $childBindings] = $this->compile((array) $child, $table);
            $parts[] = "({$sql})";
            $bindings = [...$bindings, ...$childBindings];
        }

        return ['('.implode(" {$glue} ", $parts).')', $bindings];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: list<mixed>}
     */
    private function condition(array $node, ?string $table): array
    {
        $field = (string) $node['field'];
        $op = (string) $node['op'];
        $value = $node['value'] ?? null;

        if (in_array($field, PredicateParser::COLUMNS, true)) {
            return $this->column($this->qualify($field, $table), $op, $value);
        }

        $parts = explode('.', $field);
        $column = $this->qualify((string) array_shift($parts), $table);

        if ($op === 'exists') {
            return [$this->json->exists($column, $parts), []];
        }

        if ($op === 'not_exists') {
            return ['NOT ('.$this->json->exists($column, $parts).')', []];
        }

        $isString = $this->json->isType($column, $parts, 'string');
        $text = $this->json->text($column, $parts);
        $number = $this->json->number($column, $parts);
        $param = $this->json->numberParam();

        switch ($op) {
            case 'eq':
            case 'neq':
                $cmp = $op === 'eq' ? '=' : '<>';

                if (is_bool($value)) {
                    $sql = $this->json->booleanEquals($column, $parts);
                    $isBool = $this->json->isType($column, $parts, 'boolean');

                    return $op === 'eq'
                        ? ["({$sql})", [$value ? 'true' : 'false']]
                        : ["({$isBool} AND NOT ({$sql}))", [$value ? 'true' : 'false']];
                }

                if (is_string($value)) {
                    return ["({$isString} AND {$text} {$cmp} ?)", [$value]];
                }

                return ["({$number} {$cmp} {$param})", [$value]];

            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                $cmp = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];

                return ["({$number} {$cmp} {$param})", [$value]];

            case 'in':
            case 'not_in':
                $list = array_values((array) $value);
                $negate = $op === 'not_in' ? 'NOT ' : '';
                $numeric = is_int($list[0]) || is_float($list[0]);
                $placeholders = implode(', ', array_fill(0, count($list), $numeric ? $param : '?'));

                return $numeric
                    ? ["({$number} IS NOT NULL AND {$number} {$negate}IN ({$placeholders}))", [...$list]]
                    : ["({$isString} AND {$text} {$negate}IN ({$placeholders}))", [...$list]];

            case 'contains':
                return ["({$isString} AND LOWER({$text}) LIKE ? ESCAPE '!')", ['%'.$this->escapeLike(mb_strtolower((string) $value)).'%']];

            case 'starts_with':
                return ["({$isString} AND LOWER({$text}) LIKE ? ESCAPE '!')", [$this->escapeLike(mb_strtolower((string) $value)).'%']];
        }

        throw new InvalidArgumentException("Unsupported operator [{$op}].");
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function column(string $column, string $op, mixed $value): array
    {
        $wrapped = $this->connection->getQueryGrammar()->wrap($column);

        return match ($op) {
            'eq' => ["{$wrapped} = ?", [$value]],
            'neq' => ["{$wrapped} <> ?", [$value]],
            'gt' => ["{$wrapped} > ?", [$value]],
            'gte' => ["{$wrapped} >= ?", [$value]],
            'lt' => ["{$wrapped} < ?", [$value]],
            'lte' => ["{$wrapped} <= ?", [$value]],
            'in', 'not_in' => [
                $wrapped.($op === 'not_in' ? ' NOT' : '').' IN ('.implode(', ', array_fill(0, count((array) $value), '?')).')',
                array_values((array) $value),
            ],
            'contains' => ["LOWER({$wrapped}) LIKE ? ESCAPE '!'", ['%'.$this->escapeLike(mb_strtolower((string) $value)).'%']],
            'starts_with' => ["LOWER({$wrapped}) LIKE ? ESCAPE '!'", [$this->escapeLike(mb_strtolower((string) $value)).'%']],
            default => throw new InvalidArgumentException("Unsupported operator [{$op}] for columns."),
        };
    }

    private function qualify(string $column, ?string $table): string
    {
        return $table !== null ? "{$table}.{$column}" : $column;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
