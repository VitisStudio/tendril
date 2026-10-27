<?php

namespace VitisStudio\Tendril\Timescale;

use Closure;
use Throwable;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Probes the database for TimescaleDB: extension version, license GUC,
 * available policy functions and whether tendril_events is a hypertable.
 */
final class CapabilityDetector
{
    /** @var (Closure(): array{version: ?string, license: ?string, functions: list<string>, events_hypertable: bool})|null */
    private ?Closure $probe = null;

    public function __construct(private readonly Database $db) {}

    /**
     * Replace the probe (tests, or hosts with custom detection).
     *
     * @param  Closure(): array{version: ?string, license: ?string, functions: list<string>, events_hypertable: bool}  $probe
     */
    public function usingProbe(Closure $probe): void
    {
        $this->probe = $probe;
    }

    public function detect(): Capabilities
    {
        if ($this->probe !== null) {
            return Capabilities::fromProbe(($this->probe)());
        }

        try {
            if ($this->db->driver() !== 'pgsql') {
                return Capabilities::unavailable();
            }

            return Capabilities::fromProbe($this->probe());
        } catch (Throwable) {
            return Capabilities::unavailable(true);
        }
    }

    /**
     * @return array{version: ?string, license: ?string, functions: list<string>, events_hypertable: bool}
     */
    private function probe(): array
    {
        $connection = $this->db->connection();
        $extension = $connection->selectOne("SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'");
        $version = $extension !== null ? (string) $extension->extversion : null;

        if ($version === null) {
            return ['version' => null, 'license' => null, 'functions' => [], 'events_hypertable' => false];
        }

        $license = $connection->selectOne("SELECT current_setting('timescaledb.license', true) AS license");
        $functions = array_map(
            fn ($row) => (string) $row->proname,
            $connection->select("SELECT DISTINCT proname FROM pg_proc WHERE proname IN ('create_hypertable', 'add_compression_policy', 'add_retention_policy', 'add_tiering_policy', 'drop_chunks', 'add_continuous_aggregate_policy')"),
        );

        $hypertable = $connection->selectOne(
            'SELECT count(*) AS n FROM timescaledb_information.hypertables WHERE hypertable_name = ?',
            [Tables::EVENTS],
        );

        return [
            'version' => $version,
            'license' => $license !== null && is_string($license->license) ? $license->license : null,
            'functions' => array_values($functions),
            'events_hypertable' => $hypertable !== null && (int) $hypertable->n > 0,
        ];
    }
}
