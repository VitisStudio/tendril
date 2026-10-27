<?php

namespace VitisStudio\Tendril\Core\Storage\Maintenance;

use Carbon\CarbonImmutable;
use Throwable;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * MySQL RANGE COLUMNS(occurred_at) partitioning with a MAXVALUE catch-all
 * (p_future). New months are split off p_future ahead of time (cheap while
 * p_future is empty); old months are removed with DROP PARTITION. MySQL
 * partitioned tables cannot have foreign keys, which Tendril never uses.
 */
final class MySqlPartitionMaintenance extends PortableMaintenance
{
    public function name(): string
    {
        return 'mysql-partitions';
    }

    public function capabilities(): array
    {
        $partitioned = $this->isPartitioned();

        return [
            'driver' => 'mysql',
            'partitioned' => $partitioned,
            'partition_drop' => $partitioned,
            'chunked_delete' => true,
        ];
    }

    public function isPartitioned(): bool
    {
        try {
            return $this->partitions() !== [];
        } catch (Throwable) {
            return false;
        }
    }

    public function prepare(CarbonImmutable $until): array
    {
        $partitions = $this->partitions();

        if ($partitions === []) {
            return ['events table is not partitioned; nothing to prepare'];
        }

        $existing = array_column($partitions, 'name');
        $actions = [];
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        // Partitions must be added in ascending order after the last bounded one.
        $lastEnd = null;

        foreach ($partitions as $partition) {
            if ($partition['end'] !== null) {
                $lastEnd = $partition['end'];
            }
        }

        while ($month->lessThanOrEqualTo($until)) {
            $name = 'p'.$month->format('Ym');
            $end = $month->addMonth();

            if (! in_array($name, $existing, true) && ($lastEnd === null || $end->greaterThan($lastEnd))) {
                try {
                    $this->db->connection()->statement(sprintf(
                        "ALTER TABLE %s REORGANIZE PARTITION p_future INTO (PARTITION %s VALUES LESS THAN ('%s'), PARTITION p_future VALUES LESS THAN (MAXVALUE))",
                        Tables::EVENTS,
                        $name,
                        $end->format('Y-m-d H:i:s'),
                    ));
                    $actions[] = "created {$name}";
                    $lastEnd = $end;
                } catch (Throwable $e) {
                    $actions[] = "could not create {$name}: {$e->getMessage()}";
                }
            }

            $month = $end;
        }

        return $actions;
    }

    public function pruneBefore(CarbonImmutable $cutoff, int $chunk, ?callable $progress = null): int
    {
        $drop = [];
        $rows = 0;

        foreach ($this->partitions() as $partition) {
            if ($partition['end'] !== null && $partition['end']->lessThanOrEqualTo($cutoff)) {
                $drop[] = $partition['name'];
                $rows += $partition['rows'];
            }
        }

        if ($drop !== []) {
            $this->db->connection()->statement(sprintf('ALTER TABLE %s DROP PARTITION %s', Tables::EVENTS, implode(', ', $drop)));
        }

        return $rows + parent::pruneBefore($cutoff, $chunk, $progress);
    }

    public function describe(): array
    {
        return parent::describe() + ['partitions' => array_map(fn (array $p) => [
            'name' => $p['name'],
            'end' => $p['end']?->toIso8601String(),
            'rows' => $p['rows'],
        ], $this->partitions())];
    }

    /**
     * @return list<array{name: string, end: ?CarbonImmutable, rows: int}>
     */
    private function partitions(): array
    {
        $rows = $this->db->connection()->select(
            'SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS bound, TABLE_ROWS AS estimate
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
             ORDER BY PARTITION_ORDINAL_POSITION',
            [Tables::EVENTS],
        );

        $out = [];

        foreach ($rows as $row) {
            $bound = trim((string) $row->bound, "' ");

            $out[] = [
                'name' => (string) $row->name,
                'end' => strtoupper($bound) === 'MAXVALUE' || $bound === '' ? null : CarbonImmutable::parse($bound, 'UTC'),
                'rows' => max(0, (int) $row->estimate),
            ];
        }

        return $out;
    }
}
