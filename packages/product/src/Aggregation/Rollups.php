<?php

namespace VitisStudio\Tendril\Product\Aggregation;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Write helpers for tendril_rollups (delete-and-replace per bucket/kind).
 */
final class Rollups
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param  list<string>  $kinds
     */
    public function clear(string $granularity, CarbonImmutable $bucket, array $kinds): void
    {
        $this->db->table(Tables::ROLLUPS)
            ->where('granularity', $granularity)
            ->where('bucket_start', $this->db->time($bucket))
            ->whereIn('kind', $kinds)
            ->delete();
    }

    /**
     * @param  array<string, array{tenant_type: string, tenant_id: string, kind: string, dimension: string, count: int, sum: int}>  $rows
     */
    public function insert(string $granularity, CarbonImmutable $bucket, array $rows): void
    {
        $bucketValue = $this->db->time($bucket);
        $payload = [];

        foreach ($rows as $row) {
            if ($row['count'] === 0 && $row['sum'] === 0) {
                continue;
            }

            $payload[] = [
                'granularity' => $granularity,
                'bucket_start' => $bucketValue,
                'tenant_type' => $row['tenant_type'],
                'tenant_id' => $row['tenant_id'],
                'kind' => $row['kind'],
                'dimension' => mb_substr($row['dimension'], 0, 191),
                'count' => $row['count'],
                'sum' => $row['sum'],
                'version' => Aggregator::VERSION,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            $this->db->table(Tables::ROLLUPS)->insert($chunk);
        }
    }

    /**
     * Rebuild day rows for the given kinds by summing that day's hour rows.
     *
     * @param  list<string>  $kinds
     */
    public function rollDay(CarbonImmutable $day, array $kinds): void
    {
        $this->clear('day', $day, $kinds);

        $count = $this->db->wrap('count');
        $sum = $this->db->wrap('sum');

        $rows = $this->db->table(Tables::ROLLUPS)
            ->select(['tenant_type', 'tenant_id', 'kind', 'dimension'])
            ->selectRaw("sum({$count}) as total_count, sum({$sum}) as total_sum")
            ->where('granularity', 'hour')
            ->where('bucket_start', '>=', $this->db->time($day))
            ->where('bucket_start', '<', $this->db->time($day->addDay()))
            ->whereIn('kind', $kinds)
            ->groupBy(['tenant_type', 'tenant_id', 'kind', 'dimension'])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[implode('|', [(string) $row->tenant_type, (string) $row->tenant_id, (string) $row->kind, (string) $row->dimension])] = [
                'tenant_type' => (string) $row->tenant_type,
                'tenant_id' => (string) $row->tenant_id,
                'kind' => (string) $row->kind,
                'dimension' => (string) $row->dimension,
                'count' => (int) $row->total_count,
                'sum' => (int) $row->total_sum,
            ];
        }

        $this->insert('day', $day, $out);
    }

    /**
     * Accumulate into a keyed row array.
     *
     * @param  array<string, array{tenant_type: string, tenant_id: string, kind: string, dimension: string, count: int, sum: int}>  $rows
     */
    public static function add(array &$rows, ?string $tenantType, ?string $tenantId, string $kind, string $dimension, int $count, int $sum = 0): void
    {
        $tenantType = (string) $tenantType;
        $tenantId = (string) $tenantId;
        $key = $tenantType."\0".$tenantId."\0".$kind."\0".$dimension;

        if (! isset($rows[$key])) {
            $rows[$key] = ['tenant_type' => $tenantType, 'tenant_id' => $tenantId, 'kind' => $kind, 'dimension' => $dimension, 'count' => 0, 'sum' => 0];
        }

        $rows[$key]['count'] += $count;
        $rows[$key]['sum'] += $sum;
    }
}
