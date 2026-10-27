# Architecture

## Package dependency direction

```
tendril-core        (no dependency on any other Tendril package)
  ^
  |-- tendril-product     (depends on core)
  |     ^
  |     |-- tendril-marketing   (depends on core + product)
  |     |     ^
  |     |     '-- tendril-destinations (depends on core + marketing)
  |     '-- tendril-filament     (depends on core + product; marketing is optional,
  |                                detected via class_exists at runtime for the
  |                                Conversions/Campaigns pages)
  '-- tendril-timescale   (depends on core only)
```

Source: each package's `composer.json` `require` block. `tendril-core` never requires Filament,
Timescale, or a vendor SDK (`packages/core/composer.json`); `TendrilFilamentServiceProvider`
checks `class_exists(MarketingServiceProvider::class)` before adding the Conversions/Campaigns
pages to `TendrilPlugin::defaultPages()` (`packages/filament/src/TendrilPlugin.php`).

## Event envelope

`tendril_events` (`packages/core/database/migrations/2026_09_30_000001_create_tendril_events_table.php`):

| Column | Type | Notes |
|---|---|---|
| `id` | `char(26)` | ULID, primary key (see ADR 0003). |
| `occurred_at` | `timestamptz(6)` (PG) / `datetime(6)` (MySQL) | When the fact happened. |
| `received_at` | same | When the server accepted it; always server time. |
| `name` | `varchar(150)` | Lower-case, dotted (`EventNormalizer::NAME_PATTERN`). |
| `version` | `smallint` | Defaults to 1. |
| `actor_type` / `actor_id` | `varchar(150)` / `varchar(64)`, nullable | Null together when anonymous. |
| `anonymous_id` | `varchar(64)`, nullable | |
| `session_id` | `char(26)`, nullable | References `tendril_sessions.id` (no FK). |
| `tenant_type` / `tenant_id` | `varchar(150)` / `varchar(64)`, nullable | Null together when tenant-less. |
| `request_id` / `trace_id` | `varchar(64)`, nullable | |
| `parent_id` | `char(26)`, nullable | Set via `Tendril::withParent()`. |
| `dedupe_key` | `varchar(191)` | Unique (plus `occurred_at` when partitioned). |
| `source` | `varchar(32)` | `app`, `http`, `auth`, `beacon`, … |
| `environment` | `varchar(32)` | |
| `properties` / `context` | `jsonb` / JSON | Business payload vs. envelope metadata. |

No foreign keys anywhere — partitioned MySQL tables cannot carry them, and the store must
tolerate deleted users/tenants. Indexes cover `occurred_at`, `(name, occurred_at)`, tenant,
actor, anonymous id and `session_id` (see the migration for the full list). With
`tendril.database.partitioned`, the table is created as a native PostgreSQL/MySQL partitioned
table and the unique key becomes `(dedupe_key, occurred_at)` (see ADR 0008).

That composite key has a known edge case: if a resend of the same event (same `dedupe_key`)
lands with a different `occurred_at` than the original — because `tendril.limits.past_policy =
clamp` rewrote an old timestamp to "now" at a different moment each time, or because a beacon
request's client timestamp drifted outside `tendril.beacon.max_clock_drift_seconds` on one
attempt but not the other — the two rows no longer collide on the unique index and both are
stored. This only applies in partitioned mode; the unpartitioned unique key on `dedupe_key` alone
has no such gap.

`tendril_sessions` holds one acquisition snapshot per analytics session (UTM fields, referrer
host/class, click IDs, landing route, counts). `tendril_touchpoints` is a one-row-per-session
derived table rebuildable from sessions (`tendril:rebuild-touchpoints`).

## Request lifecycle

```
HTTP request
   |
   v
TrackRequests::handle()            packages/core/src/Http/Middleware/TrackRequests.php
   | RequestLifecycle::begin()     context begun, session resolved (unless deferred for /tendril/collect)
   v
route / controller runs            Tendril::track() calls buffer events in memory
   |
   v
RequestLifecycle::afterController()  session state persisted into the Laravel session,
   |                                  consent-gated cookie queued on the response
   v
response sent to the browser
   |
   v
TrackRequests::terminate()          (terminable middleware: runs after the response)
   | RequestLifecycle::terminate()  page view recorded, session/presence updated
   | Flusher::flush()               buffer -> Transport::send(Batch)
   v
Transport (sync | queue | redis-buffer | null)
   |
   v
BatchWriter / RowInserter           batch insert into tendril_events (+ sessions/touchpoints/aliases)
```

`Recorder::record()` (`packages/core/src/Capture/Recorder.php`) builds the envelope, runs it
through `EventNormalizer` (bounds, future/past-skew policy, privacy scrubbing), then the
`DecisionPipeline` (`TrackingDecision` chain: track/drop/sample/redact/tag), then pushes it onto
the scoped `Buffer`. The buffer flushes early once `tendril.buffer.flush_threshold` is reached,
and always flushes at the end of the unit of work (`RequestLifecycle::terminate()`, the queue
`LifecycleSubscriber`, or the package's own `app->terminating()` fallback). `Flusher` hands the
batch to whichever `Transport` is configured; `sync` writes immediately, `queue` dispatches one
job per unit of work carrying every buffered event, `redis-buffer` pushes a compact payload onto
a Redis list for `tendril:drain` to persist, and `null` discards it. Every stage is wrapped in
`FailOpen` (`packages/core/src/Support/FailOpen.php`): a thrown exception is swallowed, an
`IngestionFailed` event is dispatched, and the host request is never interrupted
(`tendril.debug.throw` disables this in tests only).

## Sessions, identity and alias ranges

`VitisStudio\Tendril\Core\Sessions\SessionManager::resolve()` (`packages/core/src/Sessions/SessionManager.php`)
owns analytics sessions, which are distinct from the Laravel session: their own ULID, a 30-minute
inactivity timeout and a 24-hour hard cap by default, and they survive Laravel session
regeneration (the state is re-persisted in `RequestLifecycle::afterController()`, after
`session()->regenerate()`/`invalidate()` would otherwise have dropped it). A session also ends
and a new one starts when a new arrival carries different campaign parameters
(`sessions.new_on_campaign_change`, see ADR 0005), and when the anonymous id itself rotates.

Anonymous identity: in `cookieless` mode (the default) the anonymous id lives only inside the
Laravel session. In `cookie` mode, `tendril_aid` is the anonymous id, but only when
`ConsentResolver::hasAnalyticsConsent()` returns `true`; otherwise identity falls back to a
session-bound id. If consent is revoked while the browser still presents the cookie, the cookie
is expired on the response **and** a fresh anonymous id is generated — this counts as a new
visitor and starts a new session (`SessionManager::anonymousId()` and the `$identityRotated`
check in `resolve()`; see the behavior note in ADR 0013).

`tendril_aliases` records time ranges linking an anonymous id to an actor
(`valid_from`/`valid_to`), written whenever `Tendril::identify()`, `Tendril::alias()`, or an
`auth.login`/`auth.registered` fact fires. Two actors are never merged — an alias always points
anonymous-id -> actor, never actor -> actor. Logging out closes the open alias range
(`tendril.identity.close_alias_on_logout`, default `true`) so that later anonymous browsing on a
shared device is not attributed to the previous user. Anonymous visitors are attributed to
behavior during these alias time ranges at read time (aggregation and reporting), not by
rewriting old events.

## Acquisition and touchpoints

`AcquisitionParser` (`packages/core/src/Acquisition/AcquisitionParser.php`) reads UTM query
parameters, classifies the external referrer (host + class only, never the full URL — see
`ReferrerClassifier`), and, if enabled and consented, captures click IDs. A session's first
arrival with acquisition data gets a `tendril_touchpoints` row with the "effective"
source/medium: explicit UTMs win, then the referrer class (search -> organic, social -> social,
email -> email, else referral), then a click ID (-> cpc) (`Acquisition::effectiveSourceMedium()`).
Touchpoints are rebuildable from session rows (`tendril:rebuild-touchpoints`); they are what
attribution reads (see below).

## Aggregation: dirty buckets, rollups and actor activity

Derived data is never incremented in place; it is always rebuilt from raw facts for the hours
that changed. `DirtyBuckets` (`packages/core/src/Storage/DirtyBuckets.php`) tracks hour buckets
that need recomputation: every writer marks the hour of each event it inserts, so a late-arriving
event (out-of-order delivery, a backfill, an erasure rewrite) reopens an already-processed
bucket. `Aggregator::run()` (`packages/product/src/Aggregation/Aggregator.php`) claims the oldest
dirty marks, recomputes every registered processor (`events`, `sessions`, `activity`, plus
`conversions` from tendril-marketing) for that hour inside a transaction (delete-and-replace, so
reruns are idempotent), then recomputes the owning day, and completes the mark only if it was not
re-marked in the meantime (watermarking). A mark older than the pruned raw horizon
(`tendril.retention` + `Pruner::PRUNED_BEFORE`) is skipped rather than recomputed, since the raw
facts behind it no longer exist.

Two disposable layers come out of this:

- `tendril_rollups`: counts/sums per hour and day, keyed by `(granularity, bucket_start, tenant,
  kind, dimension)`. Fast for trend charts, never a source of distinct counts.
- `tendril_actor_activity`: one row per `(tenant, actor, date, kind, subject)`. The **only**
  source of distinct-actor questions — DAU/WAU/MAU, feature uniques, retention and cohort sizes
  all read this table, never rollups (ADR 0010). `ProductMetrics`, `RetentionCalculator` and
  `CohortEvaluator` all query `tendril_actor_activity`.

Both tables use `''` rather than `NULL` for "no tenant" on their unique indexes, since PostgreSQL
treats `NULL` as distinct in unique constraints (migration comment,
`packages/product/database/migrations/2026_09_30_000100_create_tendril_rollup_tables.php`).

Aggregation also owns some counters that live in core-owned tables: `tendril-product`'s
`SessionProcessor` recomputes `tendril_sessions.event_count`/`page_view_count` from raw events for
every session touched in a dirty hour (`packages/product/src/Aggregation/Processors/SessionProcessor.php`),
and `tendril-marketing`'s `ConversionProcessor` writes `tendril_actor_activity` rows of kind
`conversion` (`packages/marketing/src/Aggregation/ConversionProcessor.php`). These columns are
derived data in the same sense as rollups — written only by aggregation, never incremented at
ingest time — even though the table itself belongs to `tendril-core`.

Overlapping `tendril:aggregate` runs would delete-and-reinsert the same hour buckets
concurrently, which is unsafe (duplicate-key failures, or a bucket left doubled or empty
depending on timing). `Aggregator::run()` takes an atomic cache lock (`tendril-product.lock.*`,
see ADR 0014) before recomputing anything; a store without lock support runs unlocked, which is
the same risk the lock exists to close, so a lock-capable cache store is recommended wherever
more than one process might run `tendril:aggregate`.
Feature/funnel/cohort/conversion definitions are versioned (`DefinitionVersions`); changing one
marks its retained history dirty for recomputation (`tendril:aggregate` reports this).

Funnels, cohorts with property filters, journeys, historical previews and the event inspector
read raw events directly (bounded samples, cached per `tendril-product.report_cache_seconds`)
rather than rollups, since they need per-event predicates rollups do not retain. Dashboard
widgets are rollup-only.

## Attribution over touchpoints

`AttributionEngine::report()` (`packages/marketing/src/Attribution/AttributionEngine.php`) is
query-time, not a stored table: for each conversion it finds the actor's/visitor's touchpoints
inside the lookback window (`tendril-marketing.attribution.lookback_days`, default 90) and
credits them per the chosen model (`first_touch`, `last_touch`, `linear`, `position_based`);
conversions with no touchpoint in the window are credited to `(direct)` / `(none)`. Revenue is
allocated across touchpoints in integer minor units (`Money::allocate()`) so totals never drift
from float rounding.

## Erasure flow

```
Tendril::erase($actor) / tendril:erase
   -> ErasureManager::eraseActor()        tendril_erasures row (status=pending),
   |                                       fresh unlinkable pseudonyms generated now
   v
EraseSubject job (queued, self-redispatching, chunked)
   -> rewrites actor_id/anonymous_id on matching tendril_events rows to the erasure's pseudonyms
   -> strips configured PII property paths (tendril.privacy.erasure_strip_paths)
   -> marks every touched hour dirty (so rollups/activity get rebuilt without the PII)
   -> deletes aliases / sessions / touchpoints for the subject
   -> runs every SubjectEraser tagged "tendril.erasers" (derived data in other packages)
   -> marks the erasure completed, dispatches ErasureCompleted
```

Each erasure mints its own fresh pseudonym pair (`erased:<ulid>`), so two erasures of different
subjects never collide and nothing links a pseudonym back to the original identity (ADR 0011).
`EraseSubject` (`packages/core/src/Privacy/EraseSubject.php`) processes a bounded number of
chunks per run (`maxChunks`, default 50) and re-dispatches itself if more remain — there is no
separate progress cursor, because once a chunk is rewritten it no longer matches the original
query, so the job naturally picks up where it left off.

## Fail-open posture

Every capture-path call — `Recorder::record()`, the auth/Livewire subscribers, the lifecycle
hooks, the transports — is wrapped in `VitisStudio\Tendril\Core\Support\FailOpen`. A thrown
exception is caught, an `IngestionFailed` (or `EventRejected`/`EventDropped`) event is dispatched
for observability, and the host request/job continues unaffected. The only exception is
`tendril.debug.throw`, which is meant for the package's own test suite (`tests/BaseTestCase.php`
sets it to `true` precisely so capture bugs surface as test failures) and which `tendril:doctor`
flags as a failure if left on in production.

Failure granularity is deliberately fine-tuned per hook rather than applied uniformly:

- A host `ActorResolver`/`TenantResolver` that throws no longer costs the fact: `Recorder::actor()`
  /`Recorder::tenant()` and `RequestLifecycle::finish()` wrap the resolver call in
  `FailOpen::run(..., 'actor_resolve')` / `'tenant_resolve'`, so the event or page view is still
  recorded, just without an actor/tenant, and an `IngestionFailed` event is dispatched with that
  stage name (`packages/core/src/Capture/Recorder.php`, `packages/core/src/Http/RequestLifecycle.php`).
- A `TrackingDecision` that throws drops that one event instead: `DecisionPipeline::evaluate()`
  catches the exception, reports it via `FailOpen::report($e, 'decision')`, and returns
  `Outcome::dropped('decision_failed')` — dropping is the privacy-safe default, since a broken
  policy hook may have intended to drop or redact the event
  (`packages/core/src/Decisions/DecisionPipeline.php`).
- A throwing `ConsentResolver` is treated as "no consent" and reported through `IngestionFailed`
  with stage `consent` (`SessionManager::consent()`, `packages/core/src/Sessions/SessionManager.php`),
  rather than swallowed silently.
- The end-of-unit-of-work context/buffer reset is itself wrapped in `FailOpen::run(..., 'request_reset')`
  (`RequestLifecycle::terminate()`), so a failure clearing state cannot itself break the response.

`tests/Core/FailOpenTest.php` exercises each of these: a throwing actor resolver, a throwing
decision hook, a throwing consent resolver, and an unreachable `redis-buffer` transport both with
and without a `sync` fallback configured.

## Octane and queue context isolation

Long-lived processes (queue workers, Horizon, Octane) must never leak one request's/job's actor,
tenant, campaign or buffered events into the next. `LifecycleSubscriber`
(`packages/core/src/Context/LifecycleSubscriber.php`) listens for the boundaries of a unit of
work and resets `ContextStore` + `Buffer` (and flushes first) at each one:

- Octane: `Laravel\Octane\Events\{RequestReceived,TaskReceived,TickReceived}` clear context at
  the start; the matching `*Terminated` events flush and clear at the end. The package has no
  Octane dependency — it subscribes to these event class names as plain strings, so nothing
  breaks when Octane is absent.
- Queue jobs: `JobProcessing` resets the buffer and marks the context source `queue` (keeping
  request/trace ids propagated via Laravel `Context` serialization); `JobProcessed` /
  `JobFailed` / `JobExceptionOccurred` flush and clear. Jobs run through the `sync` queue
  connection are left alone, since they share the enclosing HTTP request's unit of work.
- Console commands: `CommandStarting` establishes a console-sourced context root (skipped for
  `queue:work`/`horizon:work`, which get their own job-level boundaries);
  `CommandFinished` flushes and clears.

`TendrilServiceProvider::registerContextHooks()` additionally hydrates a dispatched job's hidden
`Context` with the current actor/tenant (IDs only) at dispatch time, via `Context::dehydrating()`,
so a job created during a request can still resolve "who did this" without re-querying auth.
Actual Octane behavior (Swoole/FrankenPHP) is not covered by the test suite directly; tests
simulate the Octane lifecycle events on one long-lived application instance
(`tests/Support/OctaneEvents.php`).
