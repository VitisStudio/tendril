<?php

namespace VitisStudio\Tendril\Product\Cohorts;

use InvalidArgumentException;
use JsonSerializable;
use VitisStudio\Tendril\Core\Query\Predicate;

/**
 * Saved predicate over actor behavior, evaluable "as of" any date.
 *
 * Conditions:
 *   {type: performed, event, where?, min_count=1, within_days=30}
 *   {type: not_performed, event, within_days=30}
 *   {type: used_feature, feature, min_days=1, within_days=30}
 *   {type: first_seen, within_days=30}
 *   {type: actor_type, value}
 * Combined with match "all" (intersection) or "any" (union).
 *
 * Host-model attributes (plan, role…) are not queryable: Tendril has no
 * foreign keys into host tables. Track them as event properties instead.
 */
final class CohortDefinition implements JsonSerializable
{
    public const array TYPES = ['performed', 'not_performed', 'used_feature', 'first_seen', 'actor_type'];

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $match,
        public readonly array $conditions,
    ) {
        if (! in_array($match, ['all', 'any'], true)) {
            throw new InvalidArgumentException('Cohort match must be "all" or "any".');
        }

        if ($conditions === [] || count($conditions) > 10) {
            throw new InvalidArgumentException('Cohorts need between 1 and 10 conditions.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $definition = is_array($data['definition'] ?? null) ? $data['definition'] : $data;
        $conditions = [];

        foreach ((array) ($definition['conditions'] ?? []) as $i => $condition) {
            if (! is_array($condition) || ! in_array($condition['type'] ?? null, self::TYPES, true)) {
                throw new InvalidArgumentException("Cohort condition {$i} has an unknown type.");
            }

            $type = (string) $condition['type'];
            $normalized = ['type' => $type, 'within_days' => max(1, min(3660, (int) ($condition['within_days'] ?? 30)))];

            switch ($type) {
                case 'performed':
                case 'not_performed':
                    if (! is_string($condition['event'] ?? null) || $condition['event'] === '') {
                        throw new InvalidArgumentException("Cohort condition {$i} needs an event.");
                    }

                    $normalized['event'] = strtolower($condition['event']);
                    $normalized['min_count'] = max(1, (int) ($condition['min_count'] ?? 1));

                    if (is_array($condition['where'] ?? null)) {
                        $normalized['where'] = Predicate::fromArray($condition['where'])->toArray();
                    }

                    break;

                case 'used_feature':
                    if (! is_string($condition['feature'] ?? null) || $condition['feature'] === '') {
                        throw new InvalidArgumentException("Cohort condition {$i} needs a feature.");
                    }

                    $normalized['feature'] = $condition['feature'];
                    $normalized['min_days'] = max(1, (int) ($condition['min_days'] ?? 1));

                    break;

                case 'actor_type':
                    if (! is_string($condition['value'] ?? null) || $condition['value'] === '') {
                        throw new InvalidArgumentException("Cohort condition {$i} needs a value.");
                    }

                    $normalized['value'] = $condition['value'];

                    break;
            }

            $conditions[] = $normalized;
        }

        return new self(
            (string) ($data['key'] ?? 'adhoc'),
            (string) ($data['name'] ?? ($data['key'] ?? 'Cohort')),
            (string) ($definition['match'] ?? 'all'),
            $conditions,
        );
    }

    /**
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    public function definition(): array
    {
        return ['match' => $this->match, 'conditions' => $this->conditions];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'definition' => $this->definition()];
    }
}
