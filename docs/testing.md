# Testing

## Running the suite locally

The suite runs against whichever database `DB_CONNECTION` points at — PostgreSQL and MySQL in
CI, SQLite in-memory as a local convenience (`tests/BaseTestCase.php`:
`$config->set('database.default', env('DB_CONNECTION', 'testing'))`). The project's rule is:
**a test that only passes on SQLite does not count** — every database-touching behavior gets a
test that runs on both PostgreSQL and MySQL (`tests/BaseTestCase.php` docblock, `plan.md`
"Working rules for Claude Code").

```bash
# PostgreSQL
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=tendril DB_USERNAME=tendril DB_PASSWORD=secret \
  XDEBUG_MODE=off vendor/bin/pest

# MySQL
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=tendril DB_USERNAME=tendril DB_PASSWORD=secret \
  XDEBUG_MODE=off vendor/bin/pest

# Redis buffer / presence store behavior, against a real Redis, with each client:
REDIS_CLIENT=phpredis  vendor/bin/pest --filter=Redis
REDIS_CLIENT=predis    vendor/bin/pest --filter=Redis
```

`XDEBUG_MODE=off` avoids Xdebug's step-debugging/coverage overhead and noise on machines where
the extension is enabled but not needed for a given run; it has no effect on machines without
Xdebug installed.

Quick commands via Composer scripts (`composer.json`):

```bash
composer test          # vendor/bin/pest (load group excluded by phpunit.xml)
composer test:load     # vendor/bin/pest --group=load --exclude-group=none
composer analyse        # vendor/bin/phpstan analyse --memory-limit=2G
composer format          # vendor/bin/pint
composer lint            # vendor/bin/pint --test
```

## Test suites

Each top-level directory under `tests/` is its own PHPUnit testsuite (`phpunit.xml`), all using
`Tests\TestCase` (migrated once per process, each test in a transaction via
`RefreshDatabase`) except: `tests/Schema`, which uses `Tests\SchemaTestCase` so it can migrate
with different config per test (e.g. `tendril.database.partitioned`); and `tests/Scale`, which
uses `Tests\ScaleTestCase` (`tests/ScaleTestCase.php`) — it runs `migrate:fresh` and commits for
real instead of wrapping each test in a transaction, because the load/aging tests generate years
of synthetic data through the aggregation pipeline's per-bucket commits, and a single wrapping
test transaction with thousands of savepoints exhausted PostgreSQL's lock table.

| Directory | What it proves |
|---|---|
| `tests/Core` | The core package end to end: `SmokeTest` (install sanity), `EventNormalizerTest` (bounds, skew, scrubbing), `PredicateTest` (DSL parsing/evaluation/SQL compilation), `DecisionsTest` (TrackingDecision pipeline and recognizers), `ContextTest` (Context propagation/merge/clear), `MiddlewareTest` (capture middleware, page views, prefetch/wire:navigate distinction), `AuthEventsTest` (login/registered/logout facts, alias linking, remember-me), `LivewireTest` (ComponentHook capture, `#[Untracked]`), `AliasTest` (alias ranges, never merging actors), `ErasureTest` (chunked rewrite, pseudonyms, strip paths), `PrivacyCanaryTest` (passwords/tokens/credit cards/IPs never stored, swept across every Tendril table including `tendril_features`/`tendril_funnels`/`tendril_cohorts`/`tendril_conversions`, not just `tendril_events`), `WriterTest` (batch insert / dedupe), `QueueTest` (one job per unit of work, context propagation across dispatch chains), `RedisBufferTest` (claim/acknowledge, backpressure, dead letters, stale requeue), `OctaneTest` (simulated Octane lifecycle isolation between two sequential requests), `FailOpenTest` (failures never propagate to the host: a throwing actor resolver, a throwing `TrackingDecision` hook, a throwing consent resolver, and an unreachable `redis-buffer` transport with and without a `sync` fallback), `PresenceTest`, `BeaconTest` (Origin check, rate limit, clock drift, dedupe), `ObservabilityTest` (link templating), `CommandsTest` (core Artisan commands), `FakeTest` (`Tendril::fake()`/`RecordingTransport`). |
| `tests/Product` | `AggregationTest` (dirty-bucket claiming, watermarking, late-event reopening), `DerivedDataCorrectnessTest` (recomputes metrics directly from raw events on a generated dataset and asserts rollup/activity answers match exactly — rerun on every aggregation change), `FeatureDefinitionTest`, `FunnelTest` (step counts, drop-off, conversion window, historical evaluation, tenant scoping, anonymous inclusion/exclusion), `CohortRetentionTest` (behavioral cohort membership "as of" a date, weekly retention matrices), `JourneyAndCardinalityTest`, `CommandsTest` (aggregate/backfill/rebuild-rollups/features). |
| `tests/Marketing` | `AttributionTest` (first/last/linear/position-based models, lookback window, direct/none fallback), `ConversionTest` (conversion definitions and evaluation), `ClickIdTest` (opt-in capture, consent gating), `MoneyTest` (minor-unit conversion and currency exponents). |
| `tests/Filament` | `PluginTest` (registration across panels), `AuthorizationTest` (`viewTendril`/`manageTendril` gates, re-checked per Livewire request), `TenantScopingTest` (plugin override -> panel tenant -> core tenant resolver precedence, including the "tenancy but no tenant" fail-closed case, plus cross-tenant checks for the event catalog/inspector and the Presence page), `RollupQueriesTest` (widgets query rollups/activity, not raw events), `DefinitionBuilderTest` (Filament-built predicates serialize to valid DSL). |
| `tests/Destinations` | `AdaptersTest` (Webhook HMAC signing, Meta fbclid requirement, Google Ads gclid requirement — against faked HTTP, not real APIs), `DispatcherTest` (idempotent delivery rows, settle window, retry/attempt accounting). |
| `tests/Timescale` | `CapabilitiesTest` (capability detection against a replaceable probe; real TimescaleDB-specific behavior is exercised in CI's Timescale job, not locally, since the local PostgreSQL server has no TimescaleDB extension installed). |
| `tests/Scale` | Excluded from the default run (`load` group). `LoadTest.php` and `AgingTest.php` are the release-qualification benchmark described in `tests/Scale/LoadTest.php`'s docblock: at least two years of synthetic data, many tenants, skewed hot features, bursty Livewire traffic, anonymous-to-known transitions, with budgets overridable via `TENDRIL_BUDGET_*` env vars. `RetentionTest.php` exercises prune/archive at volume. Run with `composer test:load`. |
| `tests/Schema` | `MigrationSafetyTest` (no column from the 1.0 schema snapshot, `tests/Fixtures/schema-1.0.json`, is ever dropped or renamed; a fixture index migration also runs), `PartitionTest` (native partitioning on PostgreSQL/MySQL: `onlyOn('pgsql', 'mysql')`, since SQLite cannot express it). |
| `tests/Support` | `OctaneEvents.php` — shared helper that simulates Octane's lifecycle events for `tests/Core/OctaneTest.php`; not a testsuite itself. |

## The `load` group

`phpunit.xml` excludes the `load` group by default (`<exclude><group>load</group></exclude>`),
and the `composer test:load` script explicitly re-includes it
(`--group=load --exclude-group=none`). This keeps the default `composer test` run fast; the load
suite is meant for release qualification, not every CI run (it is not part of
`.github/workflows/tests.yml`'s job list — see `docs/operations.md` and the repository's
`.github/workflows/load.yml`, which runs it manually via `workflow_dispatch` on both databases).

## Same-behavior-on-both-databases rule

Every test in `tests/Core`, `tests/Product`, `tests/Marketing`, `tests/Filament`,
`tests/Destinations` and `tests/Scale` runs unmodified against whichever connection
`DB_CONNECTION` selects — there are no PostgreSQL-only or MySQL-only assertions in those suites.
Tests that are inherently database-specific (native partitioning shapes, TimescaleDB) live in
`tests/Schema` and `tests/Timescale` and guard themselves with `onlyOn('pgsql')` /
`onlyOn('pgsql', 'mysql')`, skipping cleanly on an unsupported driver rather than failing.

## Results recorded for this validation pass (2026-10-01)

- PostgreSQL 18.1: 205 passed, 3 skipped, 0 failed (1507 assertions).
- MySQL 8.0.33: 206 passed, 2 skipped, 0 failed (1508 assertions).
- SQLite in-memory (dev convenience only): 204 passed, 4 skipped.
- Skips are driver gates only: TimescaleDB-only tests, a SQLite-only migration fixture, partition
  tests that need PostgreSQL/MySQL, and "TimescaleDB not installed" against the local PostgreSQL
  server (no TimescaleDB extension available locally).
- Redis buffer and presence tests pass with both `REDIS_CLIENT=phpredis` and
  `REDIS_CLIENT=predis` against a real Redis 7.
- `vendor/bin/pint --test` is clean; `vendor/bin/phpstan analyse` (Larastan, level 8) reports no
  errors (see `docs/decisions/0012-static-analysis-level.md` for why level 8 and not `max`).

Not verified locally: the Laravel 12 / Testbench 10 matrix leg (CI-only), real Octane
(Swoole/FrankenPHP — isolation is tested by simulating Octane's lifecycle events on one
application instance), TimescaleDB-specific behavior beyond capability detection (CI's Timescale
job covers it against `timescale/timescaledb:latest-pg16`), and the Meta/Google Ads adapters
against real APIs (tests use faked HTTP).
