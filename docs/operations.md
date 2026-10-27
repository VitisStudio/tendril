# Operations

## Command reference

All commands are Artisan commands under the `tendril:` namespace. Signatures are copied from
each command's `$signature` property.

### tendril-core

| Command | Options | Purpose |
|---|---|---|
| `tendril:doctor` | `--json`, `--alert` | Validate migrations, connection, transport, presence, retention, ingestion health, dead letters, aggregation backlog, event growth, name cardinality, storage adapter and privacy posture. `--alert` dispatches `HealthThresholdExceeded` for every non-OK check. Exits non-zero on any `fail`. |
| `tendril:inspect {event} {--days=7} {--sample=500}` | | Catalog one event name: volume and version/source breakdown over `--days`, plus an inferred property schema from a bounded recent sample (`EventInspector`). |
| `tendril:drain {--batches=100} {--loop}` | | Persist batches pushed by the `redis-buffer` transport (claim -> insert -> acknowledge). `--loop` keeps draining until the buffer is empty. |
| `tendril:prune {--dry-run}` | | Apply raw-event retention: optional NDJSON archive, then chunked prune, plus session/touchpoint and dead-letter retention. `--dry-run` only reports counts. |
| `tendril:erase {--actor-type=} {--actor-id=} {--anonymous-id=} {--by=} {--force}` | | Queue an audited privacy erasure for an actor or an anonymous visitor. Prompts for confirmation unless `--force`. |
| `tendril:dead-letters {--retry} {--purge-days=} {--limit=100}` | | List the 20 most recent dead letters and the total count; `--retry` re-attempts persisting up to `--limit`; `--purge-days=N` deletes dead letters older than N days. |
| `tendril:partitions {--months=} {--describe}` | | Prepare native partitions/hypertable chunks ahead of time (`--months`, defaults to `tendril.storage.partitions_ahead_months`) and print the adapter's capabilities/description. `--describe` skips preparation. |
| `tendril:rebuild-touchpoints {--chunk=1000}` | | Rebuild `tendril_touchpoints` from session acquisition snapshots (idempotent, `insertOrIgnore`). |

### tendril-product

| Command | Options | Purpose |
|---|---|---|
| `tendril:aggregate {--limit=} {--loop}` | | Recompute rollups and actor activity for dirty hour buckets, oldest first. `--limit` overrides `tendril-product.aggregate_limit`; `--loop` drains until no dirty buckets remain. This is the command that turns raw events into reportable data — **schedule it** (see below). Before running, it takes an atomic cache lock named `tendril:aggregate` (`Aggregator::lock()`, `packages/product/src/Aggregation/Aggregator.php`, lifetime `tendril-product.lock.seconds`, default 900s) so two overlapping runs cannot delete-and-reinsert the same hour bucket concurrently. If another run already holds the lock, the command prints "Another aggregation run holds the lock; nothing was recomputed." and exits successfully. Cache stores without an atomic lock provider (see `lock.store` in `docs/configuration.md`) run unlocked. |
| `tendril:rebuild-rollups {--from=} {--to=} {--run}` | | Mark every hour bucket in a date range dirty (e.g. after fixing a calculation bug). `--run` aggregates immediately instead of leaving it to the scheduler. |
| `tendril:backfill {--from=} {--to=} {--days=7} {--restart}` | | Chunked, resumable backfill: marks and aggregates a date range in `--days`-sized steps, saving a cursor in `tendril_state` after each step so an interrupted run resumes automatically. `--restart` discards a saved cursor. `--from` is required unless resuming. |
| `tendril:features {export\|import} {path?} {--overwrite}` | | JSON export/import of feature definitions. Export writes to `path` or stdout; import reads from `path` (`--overwrite` replaces existing data-sourced definitions). |

### tendril-marketing

| Command | Options | Purpose |
|---|---|---|
| `tendril:conversions {export\|import} {path?} {--overwrite}` | | JSON export/import of conversion definitions, same shape as `tendril:features`. |

### tendril-destinations

| Command | Options | Purpose |
|---|---|---|
| `tendril:destinations:dispatch {--destination=}` | | Deliver settled conversions to configured destinations: finds conversions in the lookback window that have settled (`tendril-destinations.settle_minutes`), skips ones already delivered/skipped/exhausted, batches and sends them, and records one delivery row per `(destination, event)`. `--destination=name` runs a single destination. |

### tendril-timescale

| Command | Options | Purpose |
|---|---|---|
| `tendril:timescale:install {--force} {--detect}` | | Detect TimescaleDB capabilities (extension version, license, hypertable status) and print them. Without `--detect`, converts `tendril_events` into a hypertable and applies `tendril-timescale.chunk_interval`/`compress_after`. `--force` allows the conversion even when the table already holds more than `tendril-timescale.max_rows_for_conversion` rows (the conversion rewrites the table). |

## Scheduler recommendations

```php
// routes/console.php or the application's scheduler
Schedule::command('tendril:aggregate --loop')->everyMinute()->withoutOverlapping();
Schedule::command('tendril:drain --loop')->everyMinute()->withoutOverlapping(); // only if transport = redis-buffer
Schedule::command('tendril:prune')->daily();
Schedule::command('tendril:partitions')->monthly();                            // only with native partitioning or Timescale
Schedule::command('tendril:destinations:dispatch')->everyFiveMinutes();        // only with tendril-destinations configured
Schedule::command('tendril:doctor --alert')->everyFiveMinutes();
```

The cache lock `tendril:aggregate` takes internally removes the need for
`withoutOverlapping()` on this schedule entry specifically (a second overlapping invocation is a
no-op rather than a race), but adding `withoutOverlapping()` as well is harmless and still
recommended for commands run manually outside the scheduler.

`tendril:aggregate` is the one command every installation needs on a schedule — without it,
rollups and actor activity (and therefore every Filament widget, feature/funnel/cohort report,
and attribution query) go stale. `AggregationFreshnessCheck`
(`packages/product/src/Diagnostics/AggregationFreshnessCheck.php`) warns in `tendril:doctor` once
the last run is more than `tendril.diagnostics.max_aggregation_lag_minutes` (default 30) old, or
if it has never run.

## `tendril:doctor` checks

Core checks (`CoreChecks::all()`, `packages/core/src/Diagnostics/CoreChecks.php`): `connection`,
`migrations`, `driver` (warns on SQLite, fails on anything else unsupported), `transport`
(validates the configured driver, warns on `sync`/`sync`-queue in production, warns when the
`redis-buffer` backlog exceeds 80% of `max_pending`), `limits` (sanity-checks buffer/payload/skew
config), `presence`, `retention` (warns if raw events are kept forever without
`allow_forever`), `ingestion` (dropped+failed ratio over `max_dropped_ratio`), `dead_letters`,
`aggregation_backlog` (dirty bucket count and age), `event_growth` (24h volume, informational),
`cardinality` (distinct event names in 24h vs. `max_distinct_names_per_day`), `storage` (reports
the resolved `MaintenanceAdapter`), and `privacy` (flags non-default IP/cookie/click-ID settings,
and fails outright if `tendril.debug.throw` is on in production).

Product checks (`ProductChecks::all()`, `packages/product/src/Diagnostics/`):
`aggregation_freshness` (above) and `property_cardinality` (distinct event names over 7 days and
any property whose distinct-value count/ratio looks like an id, email or timestamp —
`tendril-product.cardinality.*`).

Timescale check (registered in `TimescaleServiceProvider::packageBooted()`): confirms
TimescaleDB is installed when `tendril.storage.adapter = timescale`, and warns if the extension
is present but `tendril_events` has not been converted with `tendril:timescale:install`.

Run `tendril:doctor --json` for machine-readable output in CI/alerting; `--alert` dispatches
`HealthThresholdExceeded` for every check that is not `ok`, so you can route alerts to your own
listener instead of parsing command output.

## Dead letters

A batch that fails to persist after retries is dead-lettered (`tendril_dead_letters`): the
payload, event count, failure reason and attempt count are kept so nothing is silently lost.
`tendril:dead-letters` lists the 20 most recent; `--retry` re-attempts persisting up to
`--limit` of them; `--purge-days=N` removes old ones. `tendril:doctor` warns whenever any dead
letters exist, so they do not go unnoticed.

## Backfills

`tendril:backfill --from=2026-01-01` processes the range in `--days`-sized chunks (default 7),
marking each chunk's hour buckets dirty and running `Aggregator::drain()` before moving on. Its
cursor is stored in `tendril_state` under `backfill.cursor`, so re-running the same command
after an interruption resumes automatically; `--restart` starts over. Use this for the initial
load of historical data, or after importing raw events from another system.

`tendril:rebuild-rollups --from= --to=` is for correctness fixes rather than backfills: it marks
an already-aggregated range dirty again (e.g. after changing a rollup calculation) without
touching raw events. `--run` aggregates immediately; otherwise leave it to the scheduled
`tendril:aggregate`.

## Prune / archive

`tendril:prune` applies `tendril.retention.raw_days` (default 395 days; `null` means forever and
requires `tendril.retention.allow_forever` or `tendril:doctor` warns — see ADR 0004). If
`tendril.retention.archive.enabled` is on, each day older than the cutoff is written to a gzip
NDJSON file on `tendril.retention.archive.disk`/`path` before being deleted
(`VitisStudio\Tendril\Core\Storage\Archiver`), with progress tracked in `tendril_state` under
`retention.archived_through` so re-running only archives new days. The pruned horizon is recorded
in `tendril_state` under `retention.raw_pruned_before` **before** rows are deleted, so
`tendril:aggregate` never tries to rebuild a bucket whose raw facts are gone. Session/touchpoint
retention (`tendril.retention.sessions_days`) and dead-letter retention
(`tendril.retention.dead_letters_days`) are pruned in the same run. Use `--dry-run` to see the
row count that would be removed without deleting anything.

## Partition maintenance

With `tendril.database.partitioned` (decided before the first migration — see ADR 0008),
`tendril:partitions` creates partitions/chunks ahead of `tendril.storage.partitions_ahead_months`
(default 3); schedule it monthly so a missing future partition never blocks an insert.
`--describe` prints the adapter's capabilities and current partition/chunk layout without
preparing anything — useful for verifying partitioning after a migration.
`tendril.storage.adapter` (`auto` by default) picks `pgsql-partitions`/`mysql-partitions`
automatically when the table is partitioned, `portable` otherwise, or `timescale` when set
explicitly.

## TimescaleDB install

1. `php artisan tendril:timescale:install --detect` — prints extension version, license and
   whether `tendril_events` is already a hypertable (`CapabilityDetector::detect()`), without
   changing anything.
2. `php artisan tendril:timescale:install` — converts `tendril_events` into a hypertable
   (`chunk_interval` from `tendril-timescale.chunk_interval`) and applies a compression policy if
   `compress_after` is set **and** the edition supports compression (not available on Apache-only
   builds, e.g. Neon — `TimescaleMaintenance::prepare()` records this as a skipped action rather
   than failing).
3. Converting a table with more rows than `tendril-timescale.max_rows_for_conversion` (default
   1,000,000) requires `--force`, because the conversion rewrites the table
   (`migrate_data`); there is no online/zero-rewrite path.
4. Set `TENDRIL_STORAGE_ADAPTER=timescale` so retention (`tendril:prune`) uses
   `drop_chunks` instead of row-by-row deletes.

## Destinations dispatch

`tendril:destinations:dispatch` finds conversions that occurred in the lookback window
(`tendril-destinations.lookback_hours`, default 72) and are old enough to have settled
(`tendril-destinations.settle_minutes`, default 5, so late-arriving events are not shipped
prematurely), skips any already delivered, skipped, or past `max_attempts`, attaches click IDs
from the conversion's session, and sends in batches of `batch_size`. Each `(destination,
event)` pair gets exactly one row in `tendril_destination_deliveries`, making delivery
idempotent and retries safe to run repeatedly. Run it on a schedule with
`withoutOverlapping()`, since two concurrent runs against the same destination are only safe if
the scheduler enforces that.

## Health events to alert on

Subscribe to these in a listener and route to your own alerting:

- `HealthThresholdExceeded` — any `tendril:doctor` check that is not `ok` (run with `--alert`).
- `IngestionFailed` — capture, hand-off, or persistence failed; `stage` tells you where.
- `EventRejected` / `EventDropped` — expected in small numbers (bad clients, policy drops);
  alert on rate, not occurrence.
- `ErasureCompleted` — useful for compliance audit trails (`counts` shows what was touched).

## Upgrade notes

The schema-safety test suite (`tests/Schema/MigrationSafetyTest.php`) asserts that no column
shipped in the 1.0 schema snapshot (`tests/Fixtures/schema-1.0.json`) is ever dropped or renamed,
plus a fixture migration for index changes — this is the extent of the upgrade-path testing;
there is no automated test for data migrations between arbitrary versions. Before upgrading in
production: run `tendril:doctor` first and after, and take a backfill/rebuild pass
(`tendril:rebuild-rollups`) if release notes call out a rollup/activity calculation change (the
aggregator version bump in `Aggregator::VERSION` triggers `AggregationFreshnessCheck` to warn you
automatically when this happens).
