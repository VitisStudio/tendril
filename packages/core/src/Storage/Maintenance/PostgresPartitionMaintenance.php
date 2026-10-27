<?php

namespace VitisStudio\Tendril\Core\Storage\Maintenance;

use Carbon\CarbonImmutable;
use Throwable;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * PostgreSQL declarative partitioning (monthly RANGE on occurred_at plus a
 * DEFAULT partition so out-of-range inserts never fail). Requires the events
 * table to have been created partitioned (tendril.database.partitioned).
 * Old months are removed with DROP TABLE; the remainder falls back to
 * chunked deletes.
 */
final class PostgresPartitionMaintenance extends PortableMaintenance
{
    public function name(): string
    {
        return 'pgsql-partitions';
    }

    public function capabilities(): array
    {
        $partitioned = $this->isPartitioned();

        return [
            'driver' => 'pgsql',
            'partitioned' => $partitioned,
            'partition_drop' => $partitioned,
            'chunked_delete' => true,
        ];
    }

    public function isPartitioned(): bool
    {
        try {
            $row = $this->db->connection()->selectOne('SELECT relkind FROM pg_class WHERE oid = to_regclass(?)', [Tables::EVENTS]);

            return $row !== null && $row->relkind === 'p';
        } catch (Throwable) {
            return false;
        }
    }

    public function prepare(CarbonImmutable $until): array
    {
        if (! $this->isPartitioned()) {
            return ['events table is not partitioned; nothing to prepare'];
        }

        $actions = [];
        $month = CarbonImmutable::now('UTC')->startOfMonth();
        $connection = $this->db->connection();

        while ($month->lessThanOrEqualTo($until)) {
            $name = self::partitionName($month);
            $exists = $connection->selectOne('SELECT to_regclass(?) AS oid', [$name]);

            if ($exists === null || $exists->oid === null) {
                try {
                    $connection->statement(sprintf(
                        "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                        $name,
                        Tables::EVENTS,
                        $month->format('Y-m-d H:i:sP'),
                        $month->addMonth()->format('Y-m-d H:i:sP'),
                    ));
                    $actions[] = "created {$name}";
                } catch (Throwable $e) {
                    $actions[] = "could not create {$name}: {$e->getMessage()}";
                }
            }

            $month = $month->addMonth();
        }

        return $actions;
    }

    public function pruneBefore(CarbonImmutable $cutoff, int $chunk, ?callable $progress = null): int
    {
        $dropped = 0;

        if ($this->isPartitioned()) {
            foreach ($this->partitions() as $partition) {
                if ($partition['end'] !== null && $partition['end']->lessThanOrEqualTo($cutoff)) {
                    $this->db->connection()->statement('DROP TABLE IF EXISTS '.$partition['name']);
                    $dropped += $partition['rows'];
                }
            }
        }

        return $dropped + parent::pruneBefore($cutoff, $chunk, $progress);
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
        if (! $this->isPartitioned()) {
            return [];
        }

        $rows = $this->db->connection()->select(
            'SELECT child.relname AS name, child.reltuples AS estimate
             FROM pg_inherits
             JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
             JOIN pg_class child ON pg_inherits.inhrelid = child.oid
             WHERE parent.relname = ?
             ORDER BY child.relname',
            [Tables::EVENTS],
        );

        $out = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $start = self::monthFromName($name);

            $out[] = [
                'name' => $name,
                'end' => $start?->addMonth(),
                'rows' => max(0, (int) $row->estimate),
            ];
        }

        return $out;
    }

    public static function partitionName(CarbonImmutable $month): string
    {
        return Tables::EVENTS.'_p'.$month->format('Ym');
    }

    public static function monthFromName(string $name): ?CarbonImmutable
    {
        if (preg_match('/_p(\d{4})(\d{2})$/', $name, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');
    }
}
