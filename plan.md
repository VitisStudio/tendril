# Tendril — Implementation Plan

First-party product + marketing analytics for Laravel. Livewire 4 and Filament 5 first-class.
Full architecture spec: `docs/spec-0.2.html` (read it before starting any phase — it is the source of truth; this file is the execution order).

## Naming (settled)

| Thing | Value |
|---|---|
| Product / code name | **Tendril** |
| Composer vendor | `vitisstudio` (Packagist account) |
| Packages | `vitisstudio/tendril-core`, `vitisstudio/tendril-product`, `vitisstudio/tendril-marketing`, `vitisstudio/tendril-filament`, `vitisstudio/tendril-timescale`, `vitisstudio/tendril-destinations` |
| PHP namespace | `VitisStudio\Tendril\{Core,Product,Marketing,Filament,Timescale,Destinations}` |
| Facade | `Tendril` (`VitisStudio\Tendril\Core\Facades\Tendril`) — never `Analytics`, `Feature`, or `Context` (collide with spatie/laravel-analytics, Pennant, Laravel) |
| Config file | `config/tendril.php` |
| Table prefix | `tendril_` |
| Artisan namespace | `tendril:` (`tendril:doctor`, `tendril:aggregate`, …) |

## Runtime floors

PHP 8.4 · Laravel 12 + current major · Livewire 4 · Filament 5 · PostgreSQL 14+ and MySQL 8.0+ (both first-class, same behavior suite) · Redis optional · Octane-safe · SQLite dev-only · MariaDB/SQL Server unsupported for 1.0.

## Non-negotiable principles (from spec §20)

1. Events are append-only facts; privacy erasure is the only sanctioned mutation.
2. Definitions (features, funnels, cohorts, conversions) are data, evaluable historically.
3. Fail-open: analytics failure never blocks the host app.
4. Portable first: Postgres and MySQL are complete products; Timescale is an optional accelerator.
5. Uniques never come from rollups — DAU/WAU/MAU use the actor-activity table.
6. Capture precedes reporting — sessions, anonymous identity, acquisition are captured in Phase 1.
7. Build on Laravel primitives: `Context` for propagation, auth events for identity, after-response hooks for buffering.
8. Privacy-safe defaults: cookieless, no IP storage; persistent cookie and click IDs are opt-in.
9. No implicit PII; bounded payloads; no guessed identity.
10. Core never depends on Filament.

---

## Phase 0 — Repo skeleton  (do this first)

- [ ] Monorepo layout per spec §2: `packages/{core,product,marketing,filament,timescale,destinations}`, `workbench/`, `tests/`, `docs/`.
- [ ] Root `composer.json` with path repositories for every package; `spatie/laravel-package-tools` in each package.
- [ ] Orchestra Testbench + Workbench; `workbench/` is a real Laravel app used by integration tests and as the demo.
- [ ] Tooling: Pest, Larastan (level max), Pint, Rector (optional). Root owns CI.
- [ ] CI matrix: PHP 8.4 × Laravel majors × {PostgreSQL 14, MySQL 8.0} via services. SQLite is not in the matrix for anything that touches the database.
- [ ] Release: lockstep versions across packages; split to read-only per-package repos on tag (symplify/monorepo-builder or splitsh action — decide, record in `docs/decisions/`).
- [ ] `docs/decisions/` ADR folder; first ADR = naming above.

**Done when:** `composer test` runs a trivial Pest test against both databases in CI, and `workbench` boots.

## Phase 1 — Core facts & capture  (`tendril-core`)

Everything here is non-backfillable; nothing in later phases may be needed to make Phase 1 data complete.

- [ ] **Event schema** (spec §4): append-only `tendril_events` with `id` (ULID), `occurred_at`/`received_at` (`timestamptz` / `DATETIME(6)`), `name`, `version`, `actor_type/id`, `anonymous_id`, `session_id`, `tenant_type/id`, `request_id`, `trace_id`, `parent_id`, `dedupe_key` (unique), `source`, `environment`, `properties` JSON, `context` JSON. No foreign keys. Configurable DB connection.
- [ ] Event normalizer + validator: bounded name length, property depth/count, payload size, future-skew rejection.
- [ ] **Context** (spec §6): keys + resolvers on Laravel `Context`; `request_id` as root of a `Context::push` trace stack with a depth cap; hidden keys for identity-adjacent values; explicit clear on request-terminated, job-processed, and Octane request-terminated.
- [ ] Actor resolver + tenant resolver contracts with sensible defaults (`auth()->user()`, null tenant).
- [ ] **Sessions** (spec §9): `tendril_sessions` with its own ULID, 30-min inactivity default, 24-h hard cap, survives Laravel session regeneration, new session on new campaign params. Holds landing route, referrer, acquisition snapshot, actor-at-end.
- [ ] **Anonymous identity**: cookieless by default (bound to Laravel session); opt-in persistent first-party cookie gated by consent hook. `tendril_aliases` with effective time ranges; never merge two actors.
- [ ] **Acquisition capture**: UTM source/medium/campaign/content/term, referrer, landing page → session row + `tendril_touchpoints` (one row per session with acquisition data). Click IDs opt-in.
- [ ] **HTTP middleware**: builds context, records page views by named route (URI pattern fallback, never resolved path), buffers in memory, flushes after response (terminable middleware / `dispatchAfterResponse`). Distinguish `wire:navigate` fetches from prefetches.
- [ ] **Auth events** → facts: `Login`→`auth.login`, `Registered`→`auth.registered` (+ alias merge), `Logout`/`CurrentDeviceLogout`/`OtherDeviceLogout`→`auth.logout` with reason. Never record `Authenticated`.
- [ ] **Transports** (spec §7): `sync`, `queue` (one job per request carrying all its events), `null`. Batch inserts. Fail-open with bounded buffer; ingestion-failure Laravel events.
- [ ] **TrackingDecision** hook (spec §12): track / drop / sample / redact / tag. Built-in recognizers (bots, health checks, routes, environments) are opt-in helpers, policy belongs to the host.
- [ ] Property allow/block lists; IP not stored by default.
- [ ] **Erasure**: chunked queued job that tombstones actor/anonymous columns, deletes aliases/sessions, strips PII properties, queues rollup rebuild. Audited.
- [ ] Public API: `Tendril::track()`, `Tendril::withContext()`, `Tendril::identify()`, `Tendril::alias()`.
- [ ] CLI: `tendril:doctor` (migrations, queue, connection, limits), `tendril:inspect {event}`.
- [ ] Tests: unit (normalization, context merge/clear, redaction, alias ranges), contract suite on both DBs, integration (middleware + auth lifecycle, queue propagation across chains/batches, no context leak in long-lived workers), privacy canaries (passwords, auth headers, CSRF tokens never stored).

**Done when:** the workbench app installed with only `tendril-core` records page views, auth facts, sessions and acquisition correctly on both databases, and a simulated Redis/DB outage does not break a request.

## Phase 2 — Livewire, presence & runtime hardening

- [ ] Livewire 4 capture via PHP `ComponentHook` (`call`, `hydrate`, `dehydrate`) registered from the service provider. Records component class + action name; never parameters. Excludes `wire:poll`, lazy/island loads, `wire:model.live` syncs without an action, upload chunking. Multiple actions per `/livewire/update` → one event each, shared `request_id`.
- [ ] Presence (spec §8): Redis/cache key with configurable TTL (5 min default), rate-limited refresh, tenant-scoped counts, degrades without breaking capture.
- [ ] `redis-buffer` transport: compact buffer + batch inserts, dedupe on `dedupe_key`, backpressure limits, dead-letter visibility.
- [ ] Octane: buffer flushed per request; isolation test with two sequential requests from different users on Swoole and FrankenPHP.
- [ ] Optional **client beacon**: dependency-free script, `navigator.sendBeacon` → `/tendril/collect`; server validates, applies TrackingDecision, dedupes. Off by default.
- [ ] Performance budgets recorded in `docs/performance.md`: middleware overhead p95/p99, envelope size, queue lag, flush latency, dropped-event rate.
- [ ] Failure injection tests: Redis down, DB down, queue paused, duplicate delivery, partial batch failure, clock skew, oversized properties, worker crash between dequeue and persist.

## Phase 3 — Product analytics  (`tendril-product`)

- [ ] Feature definitions: `Tendril::feature('reporting')->events([...])->routes([...])`, stored as data with JSON import/export.
- [ ] **Rollups** (counts/sums/durations): hourly + daily, keyed by tenant/event/feature; versioned, rebuildable, idempotent with watermarks; late events reopen buckets.
- [ ] **Actor-activity table** `(tenant, actor, date, feature|event)` for every uniques question (DAU/WAU/MAU, feature uniques, retention, cohort sizes).
- [ ] Funnels (ordered predicates + window), cohorts (saved predicates), retention matrices, journeys — all evaluable historically.
- [ ] Property query DSL for predicates (decide + ADR; keep it small and portable to both DBs).
- [ ] CLI: `tendril:aggregate`, `tendril:rebuild-rollups`, `tendril:backfill --from=`, `tendril:prune`.
- [ ] Cardinality guards on event names and property values.
- [ ] **Derived-data correctness suite**: recompute every metric directly from raw events on a generated dataset and assert rollup/activity answers match exactly. Runs on every aggregation change.

## Phase 4 — Filament 5 plugin  (`tendril-filament`)

- [ ] Plugin registration for multiple panels; authorization/policies; tenant scope defaults to `Filament::getTenant()` and is overridable via the core tenant resolver.
- [ ] Pages: Overview, Users, Features, Events, Funnels, Cohorts & Retention, Presence, Health, Settings. (Conversions and Campaigns come in Phase 5.)
- [ ] Widgets read rollups/activity tables only; polling off by default, manual refresh.
- [ ] Definition builders serialize valid DSL predicates; historical preview.
- [ ] Tests: registration across panels, authorization + tenant scoping on every resource/widget/page, dashboard queries hit rollups.

## Phase 5 — Marketing reporting  (`tendril-marketing`)

- [ ] Conversion definitions over facts (event + predicate + optional value), managed in Filament and via code/JSON.
- [ ] Attribution over `tendril_touchpoints`: first-touch, last-touch; configurable lookback (90 d default); model + window stamped on every report. Room for linear/position-based.
- [ ] Revenue in minor units with currency.
- [ ] Click-ID capture (opt-in, consent-gated, same retention as anonymous ID).
- [ ] Campaign reporting, anonymous→known conversion paths.
- [ ] Filament: Conversions and Campaigns pages.

## Phase 6 — Scale adapters  (`tendril-timescale` + core maintenance adapters)

- [ ] Storage/maintenance adapter interface; native partition strategies for Postgres and MySQL (no FKs; document the difference).
- [ ] Timescale adapter: hypertable + capability detection; never assume compression/continuous aggregates exist (Neon caveat).
- [ ] Retention: hot raw → export/archive → prune. Chunked, resumable, observable backfills.
- [ ] Large-table migration tests from snapshots of previous package versions (no full rewrites, no long locks).
- [ ] Load & aging tests: ≥ 2 years of synthetic data, many tenants, skewed hot features, bursty Livewire traffic. Measure everything listed in spec §17.

## Phase 7 — Destinations  (`tendril-destinations`)

- [ ] Conversion destination contract (consumes normalized conversions; never reshapes core events).
- [ ] First adapters only after Phase 5 semantics are stable.

---

## Open decisions to record as ADRs before the relevant phase

1. Monorepo split tooling and changelog strategy (Phase 0).
2. ULID storage type: `CHAR(26)` vs `BINARY(16)` — affects index size at year two (Phase 1).
3. Default raw-event retention, and whether "forever" requires an explicit opt-in (Phase 1).
4. Whether campaign change mid-session starts a new session or only a new touchpoint (Phase 1).
5. Whether the client beacon lives in core or its own package (Phase 2).
6. Predicate/query DSL grammar (Phase 3).
7. Whether 1.0 ships native partition management or leaves it to the host DBA (Phase 6).
8. Whether Laravel 11 is worth supporting given Filament 5 permits 11.28+ (Phase 0 — currently: no).

## Working rules for Claude Code

- Read `docs/spec-0.2.html` section referenced by each task before implementing it.
- Every database behavior gets a test that runs on both PostgreSQL and MySQL; a test that only passes on SQLite does not count.
- No new dependency on Filament, Timescale, or a vendor SDK inside `tendril-core`.
- Prefer Laravel primitives (`Context`, auth events, terminable middleware, `dispatchAfterResponse`) over parallel infrastructure.
- Any deviation from the spec gets an ADR in `docs/decisions/`, not a silent change.
