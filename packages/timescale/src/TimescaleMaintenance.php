<?php

namespace VitisStudio\Tendril\Timescale;

use Carbon\CarbonImmutable;
use RuntimeException;
use VitisStudio\Tendril\Core\Storage\Maintenance\PortableMaintenance;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Timescale maintenance adapter. Uses drop_chunks for retention when
 * tendril_events is a hypertable and falls back to portable chunked deletes
 * otherwise; compression is applied only when the edition supports it.
 */
final class TimescaleMaintenance extends PortableMaintenance
{
    public function __construct(Database $db, private readonly CapabilityDetector $detector)
    {
        parent::__construct($db);
    }

    public function name(): string
    {
        return 'timescale';
    }

    public function capabilities(): array
    {
        return $this->detector->detect()->toArray() + ['chunked_delete' => true];
    }

    public function prepare(CarbonImmutable $until): array
    {
        $capabilities = $this->detector->detect();
        $actions = [];

        if (! $capabilities->eventsIsHypertable) {
            return ['tendril_events is not a hypertable; run tendril:timescale:install'];
        }

        $compressAfter = config('tendril-timescale.compress_after');

        if (is_string($compressAfter) && $compressAfter !== '') {
            if (! $capabilities->compression) {
                $actions[] = 'compression requested but not supported by this TimescaleDB edition; skipped';
            } else {
                $connection = $this->db->connection();
                $connection->statement('ALTER TABLE '.Tables::EVENTS." SET (timescaledb.compress, timescaledb.compress_orderby = 'occurred_at DESC')");
                $connection->statement('SELECT add_compression_policy(CAST(? AS regclass), CAST(? AS interval), if_not_exists => true)', [Tables::EVENTS, $compressAfter]);
                $actions[] = "compression policy after {$compressAfter}";
            }
        }

        return $actions;
    }

    public function pruneBefore(CarbonImmutable $cutoff, int $chunk, ?callable $progress = null): int
    {
        $capabilities = $this->detector->detect();

        if (! $capabilities->eventsIsHypertable) {
            return parent::pruneBefore($cutoff, $chunk, $progress);
        }

        $before = $this->db->table(Tables::EVENTS)->where('occurred_at', '<', $this->db->time($cutoff))->count();
        $this->db->connection()->statement('SELECT drop_chunks(CAST(? AS regclass), older_than => CAST(? AS timestamptz))', [Tables::EVENTS, $this->db->time($cutoff)]);

        // drop_chunks removes whole chunks only; delete the partial remainder.
        return $before - $this->db->table(Tables::EVENTS)->where('occurred_at', '<', $this->db->time($cutoff))->count()
            + parent::pruneBefore($cutoff, $chunk, $progress);
    }

    public function describe(): array
    {
        return parent::describe() + ['timescale' => $this->detector->detect()->toArray()];
    }

    /**
     * Convert tendril_events into a hypertable. Unique indexes must include
     * the time column, so the primary key becomes (id, occurred_at) and the
     * dedupe key (dedupe_key, occurred_at). migrate_data rewrites existing
     * rows: do this at install time or in a maintenance window.
     *
     * @return list<string>
     */
    public function install(bool $force = false): array
    {
        $capabilities = $this->detector->detect();

        if (! $capabilities->hypertable) {
            throw new RuntimeException('TimescaleDB with create_hypertable is not available on this connection.');
        }

        if ($capabilities->eventsIsHypertable) {
            return ['tendril_events is already a hypertable'];
        }

        $rows = $this->db->table(Tables::EVENTS)->count();
        $max = (int) config('tendril-timescale.max_rows_for_conversion', 1000000);

        if ($rows > $max && ! $force) {
            throw new RuntimeException("tendril_events has {$rows} rows; converting rewrites the table. Re-run with --force in a maintenance window.");
        }

        $connection = $this->db->connection();
        $table = Tables::EVENTS;
        $interval = (string) config('tendril-timescale.chunk_interval', '7 days');

        $connection->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_pkey");
        $connection->statement("ALTER TABLE {$table} ADD PRIMARY KEY (id, occurred_at)");
        $connection->statement('DROP INDEX IF EXISTS tendril_events_dedupe_unique');
        $connection->statement("CREATE UNIQUE INDEX tendril_events_dedupe_unique ON {$table} (dedupe_key, occurred_at)");
        $connection->statement(
            'SELECT create_hypertable(CAST(? AS regclass), CAST(? AS name), chunk_time_interval => CAST(? AS interval), migrate_data => true, if_not_exists => true)',
            [$table, 'occurred_at', $interval],
        );

        return ["converted {$table} to a hypertable ({$interval} chunks, {$rows} rows migrated)"];
    }
}
