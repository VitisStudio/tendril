<?php

namespace VitisStudio\Tendril\Core\Query;

use Illuminate\Database\Query\Builder;
use JsonSerializable;
use VitisStudio\Tendril\Core\Exceptions\InvalidPredicate;

/**
 * Tendril's small, portable property predicate DSL (JSON):
 *
 *   node      := group | condition
 *   group     := {"all": [node…]} | {"any": [node…]} | {"not": node}
 *   condition := {"field": field, "op": op, "value"?: scalar | [scalar…]}
 *   field     := "name" | "source" | "version"
 *              | "properties.<key>[.<key>…]" | "context.<key>[.<key>…]"
 *   op        := eq neq gt gte lt lte in not_in contains starts_with exists not_exists
 *
 * Semantics (identical in SQL on PostgreSQL/MySQL and in PHP):
 * - comparisons on a missing field are false (use not_exists);
 * - string ops only match JSON strings; numeric ops only JSON numbers;
 * - eq with a boolean only matches JSON booleans;
 * - contains / starts_with are case-insensitive.
 */
final class Predicate implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $root  normalized tree
     */
    private function __construct(private readonly array $root) {}

    /**
     * @param  array<string, mixed>|null  $tree
     */
    public static function fromArray(?array $tree): self
    {
        return new self(PredicateParser::normalize($tree ?? ['all' => []]));
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw InvalidPredicate::because('not valid JSON');
        }

        return self::fromArray($decoded);
    }

    public static function always(): self
    {
        return new self(['all' => []]);
    }

    /**
     * Convenience: all conditions ANDed, e.g. [['properties.plan', 'eq', 'pro']].
     *
     * @param  list<array{0: string, 1: string, 2?: mixed}>  $conditions
     */
    public static function where(array $conditions): self
    {
        $nodes = [];

        foreach ($conditions as $condition) {
            $node = ['field' => $condition[0], 'op' => $condition[1]];

            if (array_key_exists(2, $condition)) {
                $node['value'] = $condition[2];
            }

            $nodes[] = $node;
        }

        return self::fromArray(['all' => $nodes]);
    }

    public function isEmpty(): bool
    {
        return isset($this->root['all']) && $this->root['all'] === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->root;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->root;
    }

    /**
     * @param  array<string, mixed>  $event  with name/source/version/properties/context
     */
    public function matches(array $event): bool
    {
        return PredicateEvaluator::evaluate($this->root, $event);
    }

    /**
     * Constrain an events query. $table qualifies columns when joining.
     */
    public function apply(Builder $query, ?string $table = null): Builder
    {
        if ($this->isEmpty()) {
            return $query;
        }

        [$sql, $bindings] = PredicateCompiler::for($query->getConnection())->compile($this->root, $table);

        return $query->whereRaw($sql, $bindings);
    }
}
