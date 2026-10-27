<?php

namespace VitisStudio\Tendril\Marketing\Conversions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use VitisStudio\Tendril\Core\Identity\AliasResolver;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Evaluates a conversion definition over raw facts (reads only events of the
 * definition's name, via the name/occurred_at index). Historical by design.
 */
final class ConversionEvaluator
{
    public function __construct(private readonly Database $db) {}

    public function query(ConversionDefinition $conversion, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): Builder
    {
        $query = $scope->applyToRaw($this->db->table(Tables::EVENTS))
            ->where('name', $conversion->event)
            ->where('occurred_at', '>=', $this->db->time($from))
            ->where('occurred_at', '<', $this->db->time($to));

        $conversion->where->apply($query);

        return $query;
    }

    /**
     * @return list<ConversionRecord>
     */
    public function records(ConversionDefinition $conversion, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, ?int $limit = null): array
    {
        $query = $this->query($conversion, $from, $to, $scope)
            ->select(['id', 'occurred_at', 'actor_type', 'actor_id', 'anonymous_id', 'session_id', 'tenant_type', 'tenant_id', 'properties'])
            ->orderBy('occurred_at')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $rows = $query->get();
        $aliases = AliasResolver::make();
        $aliases->load($rows->filter(fn ($r) => $r->actor_id === null && is_string($r->anonymous_id))->pluck('anonymous_id')->unique()->values()->all());

        $records = [];

        foreach ($rows as $row) {
            $at = Instant::parse($row->occurred_at);
            $properties = is_string($row->properties) ? (json_decode($row->properties, true) ?: []) : [];
            [$value, $currency] = $conversion->valueFrom(is_array($properties) ? $properties : []);
            $subject = $aliases->subject(
                is_string($row->actor_type) ? $row->actor_type : null,
                is_scalar($row->actor_id) ? (string) $row->actor_id : null,
                is_string($row->anonymous_id) ? $row->anonymous_id : null,
                $at,
            );

            $records[] = new ConversionRecord(
                $conversion->key,
                (string) $row->id,
                $at,
                $subject !== null && ! $subject->isAnonymous() ? $subject : null,
                is_string($row->anonymous_id) ? $row->anonymous_id : null,
                is_string($row->session_id) ? $row->session_id : null,
                is_string($row->tenant_type) ? $row->tenant_type : null,
                is_scalar($row->tenant_id) ? (string) $row->tenant_id : null,
                $value,
                $currency,
            );
        }

        return $records;
    }

    /**
     * Historical preview for definition builders.
     *
     * @return array{conversions: int, converters: int, values: array<string, int>}
     */
    public function summary(ConversionDefinition $conversion, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $values = [];
        $subjects = [];
        $records = $this->records($conversion, $from, $to, $scope);

        foreach ($records as $record) {
            if (($subject = $record->subject()) !== null) {
                $subjects[$subject->key()] = true;
            }

            if ($record->valueMinor !== null && $record->currency !== null) {
                $values[$record->currency] = ($values[$record->currency] ?? 0) + $record->valueMinor;
            }
        }

        ksort($values);

        return ['conversions' => count($records), 'converters' => count($subjects), 'values' => $values];
    }
}
