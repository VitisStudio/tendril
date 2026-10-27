<?php

namespace VitisStudio\Tendril\Core\Diagnostics;

use Closure;
use Illuminate\Support\Facades\Schema;
use Throwable;
use VitisStudio\Tendril\Core\Contracts\DiagnosticCheck;
use VitisStudio\Tendril\Core\Contracts\PresenceStore;
use VitisStudio\Tendril\Core\Storage\DeadLetters;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Storage\IngestionStats;
use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Core health checks: connection, migrations, transport/queue, presence,
 * retention, ingestion health, dead letters, aggregation backlog, event
 * growth, name cardinality, storage capabilities and privacy posture.
 */
final class CoreChecks
{
    /**
     * @return list<DiagnosticCheck>
     */
    public static function all(): array
    {
        return [
            self::check('connection', function (): CheckResult {
                $db = app(Database::class);
                $db->connection()->select('select 1');

                return CheckResult::ok('connection', 'Connected to ['.($db->connectionName() ?? 'default').'] ('.$db->driver().').', ['driver' => $db->driver()]);
            }),

            self::check('migrations', function (): CheckResult {
                $db = app(Database::class);
                $missing = array_values(array_filter(Tables::core(), fn (string $t) => ! Schema::connection($db->connectionName())->hasTable($t)));

                return $missing === []
                    ? CheckResult::ok('migrations', 'All core tables exist.')
                    : CheckResult::fail('migrations', 'Missing tables: '.implode(', ', $missing).'. Run php artisan migrate.', ['missing' => $missing]);
            }),

            self::check('driver', function (): CheckResult {
                $driver = app(Database::class)->driver();

                return match ($driver) {
                    'pgsql', 'mysql' => CheckResult::ok('driver', "{$driver} is supported."),
                    'sqlite' => CheckResult::warn('driver', 'SQLite is supported for development only.'),
                    default => CheckResult::fail('driver', "{$driver} is not supported (PostgreSQL 14+ / MySQL 8.0+ only)."),
                };
            }),

            self::check('transport', function (): CheckResult {
                $driver = (string) config('tendril.transport.driver');

                if (! in_array($driver, ['sync', 'queue', 'redis-buffer', 'null'], true)) {
                    return CheckResult::fail('transport', "Unknown transport [{$driver}].");
                }

                if ($driver === 'null' || ! config('tendril.enabled', true)) {
                    return CheckResult::warn('transport', 'Analytics capture is disabled.');
                }

                if ($driver === 'queue') {
                    $connection = config('tendril.transport.queue.connection') ?: config('queue.default');
                    $queueDriver = config("queue.connections.{$connection}.driver");

                    if ($queueDriver === null) {
                        return CheckResult::fail('transport', "Queue connection [{$connection}] is not configured.");
                    }

                    if ($queueDriver === 'sync' && app()->environment('production')) {
                        return CheckResult::warn('transport', 'Queue transport uses the sync queue driver in production; writes happen in-process after the response.');
                    }

                    return CheckResult::ok('transport', "queue transport on [{$connection}] ({$queueDriver}).");
                }

                if ($driver === 'redis-buffer') {
                    $pending = app(\VitisStudio\Tendril\Core\Contracts\BufferStore::class)->pending();
                    $max = (int) config('tendril.transport.redis_buffer.max_pending', 50000);

                    return $max > 0 && $pending > $max * 0.8
                        ? CheckResult::warn('transport', "Redis buffer is {$pending}/{$max} batches; run tendril:drain more often.", ['pending' => $pending])
                        : CheckResult::ok('transport', "redis-buffer transport, {$pending} batches pending.", ['pending' => $pending]);
                }

                return app()->environment('production')
                    ? CheckResult::warn('transport', 'sync transport in production writes in-process; prefer queue.')
                    : CheckResult::ok('transport', 'sync transport.');
            }),

            self::check('limits', function (): CheckResult {
                $issues = [];
                $threshold = (int) config('tendril.buffer.flush_threshold', 250);
                $max = (int) config('tendril.buffer.max_events', 1000);

                if ($max < $threshold) {
                    $issues[] = "buffer.max_events ({$max}) is below flush_threshold ({$threshold})";
                }

                if ((int) config('tendril.limits.max_payload_bytes', 16384) > 65536) {
                    $issues[] = 'max_payload_bytes above 64 KiB bloats the largest table';
                }

                if ((int) config('tendril.limits.future_skew_seconds', 300) > 3600) {
                    $issues[] = 'future_skew_seconds above one hour lets bad clocks through';
                }

                if (config('tendril.privacy.blocked_keys') === [] || config('tendril.privacy.blocked_keys') === null) {
                    $issues[] = 'privacy.blocked_keys is empty';
                }

                return $issues === []
                    ? CheckResult::ok('limits', 'Payload, buffer and clock limits are sane.')
                    : CheckResult::warn('limits', implode('; ', $issues).'.');
            }),

            self::check('presence', function (): CheckResult {
                if (! config('tendril.presence.enabled', true)) {
                    return CheckResult::ok('presence', 'Presence disabled.');
                }

                $store = app(PresenceStore::class);
                $store->touch('doctor', 'probe', time(), 10);
                $store->forget('doctor', 'probe');

                return CheckResult::ok('presence', 'Presence store ['.config('tendril.presence.store').'] reachable.');
            }),

            self::check('retention', function (): CheckResult {
                $days = config('tendril.retention.raw_days');

                if ($days === null || $days === '' || (int) $days <= 0) {
                    return config('tendril.retention.allow_forever', false)
                        ? CheckResult::ok('retention', 'Raw events are kept forever (explicit opt-in).')
                        : CheckResult::warn('retention', 'Raw events are kept forever without tendril.retention.allow_forever; set raw_days or opt in explicitly.');
                }

                return CheckResult::ok('retention', "Raw events kept for {$days} days.");
            }),

            self::check('ingestion', function (): CheckResult {
                $stats = app(IngestionStats::class)->summary(1);
                $accepted = max(1, $stats['accepted'] + $stats['dropped'] + $stats['rejected']);
                $ratio = ($stats['dropped'] + $stats['failed']) / $accepted;
                $max = (float) config('tendril.diagnostics.max_dropped_ratio', 0.01);
                $avgLag = $stats['batches'] > 0 ? (int) round($stats['lag_ms_total'] / $stats['batches']) : 0;
                $data = $stats + ['avg_lag_ms' => $avgLag];

                return $ratio > $max
                    ? CheckResult::warn('ingestion', sprintf('%.2f%% of events dropped or failed today.', $ratio * 100), $data)
                    : CheckResult::ok('ingestion', "Accepted {$stats['accepted']}, persisted {$stats['persisted']}, avg lag {$avgLag} ms today.", $data);
            }),

            self::check('dead_letters', function (): CheckResult {
                $count = app(DeadLetters::class)->count();

                return $count > 0
                    ? CheckResult::warn('dead_letters', "{$count} dead letters; inspect and run tendril:dead-letters --retry.", ['count' => $count])
                    : CheckResult::ok('dead_letters', 'No dead letters.');
            }),

            self::check('aggregation_backlog', function (): CheckResult {
                $dirty = app(DirtyBuckets::class);
                $count = $dirty->count();
                $oldest = $dirty->oldestMarkedAt();
                $maxLag = (int) config('tendril.diagnostics.max_aggregation_lag_minutes', 30);
                $lag = $oldest !== null ? (int) $oldest->diffInMinutes(Instant::now(), true) : 0;
                $data = ['dirty_buckets' => $count, 'oldest_mark_minutes' => $lag];

                if ($count > (int) config('tendril.diagnostics.max_dirty_buckets', 5000) || $lag > $maxLag) {
                    return CheckResult::warn('aggregation_backlog', "{$count} dirty buckets, oldest {$lag} min old; is tendril:aggregate scheduled?", $data);
                }

                return CheckResult::ok('aggregation_backlog', "{$count} dirty buckets.", $data);
            }),

            self::check('event_growth', function (): CheckResult {
                $db = app(Database::class);
                $since = $db->time(Instant::now()->subDay());
                $count = $db->table(Tables::EVENTS)->where('occurred_at', '>=', $since)->count();

                return CheckResult::ok('event_growth', "{$count} events in the last 24h.", ['last_24h' => $count]);
            }),

            self::check('cardinality', function (): CheckResult {
                $db = app(Database::class);
                $names = $db->table(Tables::EVENTS)
                    ->where('occurred_at', '>=', $db->time(Instant::now()->subDay()))
                    ->distinct()
                    ->count('name');
                $max = (int) config('tendril.limits.max_distinct_names_per_day', 1000);

                return $names > $max * 0.8
                    ? CheckResult::warn('cardinality', "{$names} distinct event names in 24h (limit {$max}).", ['names' => $names])
                    : CheckResult::ok('cardinality', "{$names} distinct event names in 24h.", ['names' => $names]);
            }),

            self::check('storage', function (): CheckResult {
                $adapter = app(MaintenanceManager::class)->adapter();

                return CheckResult::ok('storage', 'Storage adapter: '.$adapter->name().'.', $adapter->capabilities());
            }),

            self::check('privacy', function (): CheckResult {
                $issues = [];

                if (config('tendril.privacy.ip', 'none') !== 'none') {
                    $issues[] = 'IP handling is "'.config('tendril.privacy.ip').'"';
                }

                if (config('tendril.identity.mode') === 'cookie') {
                    $issues[] = 'persistent visitor cookie enabled (consent-gated)';
                }

                if (config('tendril.acquisition.click_ids.enabled') && ! config('tendril.acquisition.click_ids.require_consent', true)) {
                    $issues[] = 'click IDs stored without consent gating';
                }

                if (config('tendril.debug.throw') && app()->environment('production')) {
                    return CheckResult::fail('privacy', 'tendril.debug.throw is on in production: analytics can break requests.');
                }

                return $issues === []
                    ? CheckResult::ok('privacy', 'Privacy-safe defaults (cookieless, no IP storage).')
                    : CheckResult::warn('privacy', 'Non-default privacy settings: '.implode('; ', $issues).'.');
            }),
        ];
    }

    /**
     * @param  Closure(): CheckResult  $run
     */
    private static function check(string $name, Closure $run): DiagnosticCheck
    {
        return new class($name, $run) implements DiagnosticCheck
        {
            /**
             * @param  Closure(): CheckResult  $callback
             */
            public function __construct(private readonly string $checkName, private readonly Closure $callback) {}

            public function name(): string
            {
                return $this->checkName;
            }

            public function run(): CheckResult
            {
                try {
                    return ($this->callback)();
                } catch (Throwable $e) {
                    return CheckResult::fail($this->checkName, $e->getMessage());
                }
            }
        };
    }
}
