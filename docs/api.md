# Public API

## The `Tendril` facade

`VitisStudio\Tendril\Core\Facades\Tendril`, backed by the stateless singleton
`VitisStudio\Tendril\Core\Tendril` (`packages/core/src/Tendril.php`). Stateless: every
per-request value lives in scoped services or Laravel `Context`, so the facade is Octane-safe.

### `track()`

```php
Tendril::track(
    string $name,
    array $properties = [],
    ?DateTimeInterface $occurredAt = null,
    mixed $actor = null,
    mixed $tenant = null,
    ?string $dedupeKey = null,
    array $context = [],
    ?string $source = null,
    int $version = 1,
): ?string
```

Records an explicit business fact. Returns the event's ULID, or `null` when it was
rejected, dropped, sampled out, or capture is disabled — `track()` never throws into the host
app (`VitisStudio\Tendril\Core\Capture\Recorder::record()` wraps capture in
`FailOpen::run()`, unless `tendril.debug.throw` is on). `$actor`/`$tenant` accept a model, an
`ActorRef`/`TenantRef`, `false` to force anonymous/tenant-less, or `null` to resolve from the
configured resolvers. The event picks up: the current anonymous id and session id from context,
the current `Context` scalar scope (minus reserved keys), the active route under
`context.route`, and (if `privacy.ip` is not `none`) a processed client IP under `context.ip`.

```php
Tendril::track('order.completed', [
    'revenue' => '49.00',
    'currency' => 'USD',
], dedupeKey: "order-completed:{$order->id}");
```

### `withContext()`

```php
Tendril::withContext(array $context, callable $callback): mixed
```

Runs `$callback` with safe scalar context merged into every event it records, including events
recorded by jobs it dispatches (propagated through Laravel `Context`). Keys must match
`/^[A-Za-z0-9_\-]{1,64}$/`; only scalar values are kept, and strings are truncated to 255
characters.

### `withParent()`

```php
Tendril::withParent(string $eventId, callable $callback): mixed
```

Events recorded inside `$callback` get `$eventId` as their `parent_id`.

### `identify()`

```php
Tendril::identify(mixed $actor): void
```

Sets the actor for the rest of the current unit of work and, if there is a current anonymous id,
queues an alias linking that anonymous id to the actor (reason `identify`, audited in
`tendril_aliases`).

### `alias()`

```php
Tendril::alias(string $anonymousId, mixed $actor): void
```

Links an anonymous id to an actor directly (reason `alias`). Never merges two actors — an
anonymous id can only ever point to one actor's alias range at a time.

### `setTenant()` / `forTenant()`

```php
Tendril::setTenant(mixed $tenant): void
Tendril::forTenant(mixed $tenant, callable $callback): mixed
```

Set the tenant for the rest of the unit of work, or scope a callback to a tenant and restore the
previous tenant afterward.

### `flush()`

```php
Tendril::flush(): void
```

Hands the current buffer to the configured transport immediately, instead of waiting for the
request-terminated / job-processed hook.

### `erase()` / `eraseAnonymous()`

```php
Tendril::erase(mixed $actor, ?string $performedBy = null): string
Tendril::eraseAnonymous(string $anonymousId, ?string $performedBy = null): string
```

Queue an audited privacy erasure (`VitisStudio\Tendril\Core\Privacy\ErasureManager`). Returns
the erasure id. See `docs/architecture.md` for the erasure flow and
`docs/decisions/0011-erasure-pseudonyms.md`.

### `presence()`

```php
Tendril::presence(): \VitisStudio\Tendril\Core\Presence\PresenceManager
```

Returns the presence manager: `touch(ActorRef $actor, ?TenantRef $tenant, ?int $timestamp)`,
`count(?TenantRef $tenant)`, `members(?TenantRef $tenant, int $limit)`,
`forget(ActorRef $actor, ?TenantRef $tenant)` (`packages/core/src/Presence/PresenceManager.php`).

### `requestId()` / `sessionId()` / `anonymousId()`

```php
Tendril::requestId(): ?string
Tendril::sessionId(): ?string
Tendril::anonymousId(): ?string
```

Read the current unit of work's ids from `ContextStore`.

### `enabled()`

```php
Tendril::enabled(): bool
```

Whether capture is enabled and the transport is not `null`.

### `observabilityLinks()`

```php
Tendril::observabilityLinks(array|object $event): array
```

Builds URLs from `tendril.observability.links` templates (placeholders `{event_id}`,
`{request_id}`, `{trace_id}`, `{session_id}`, `{occurred_at}`) for a stored event.

### `consentUsing()` / `resolveActorUsing()` / `resolveTenantUsing()`

```php
Tendril::consentUsing(Closure $callback): void          // Closure(Request): bool
Tendril::resolveActorUsing(Closure $callback): void      // Closure(): mixed
Tendril::resolveTenantUsing(Closure $callback): void     // Closure(): mixed
```

Runtime shortcuts that swap the bound `ConsentResolver`/`ActorResolver`/`TenantResolver` for a
callback-backed implementation, without writing a class.

### `decideUsing()`

```php
Tendril::decideUsing(TrackingDecision $decision): void
```

Appends a `TrackingDecision` to the pipeline at runtime, in addition to `tendril.decisions`.

### `fake()`

```php
Tendril::fake(): \VitisStudio\Tendril\Core\Testing\RecordingTransport
```

Swaps the transport for an in-memory `RecordingTransport` and forces `tendril.enabled = true`.
See **Testing helpers** below.

### Macros: `feature()` and `conversion()`

```php
Tendril::feature(string $key): \VitisStudio\Tendril\Product\Features\FeatureBuilder   // from tendril-product
Tendril::conversion(string $key): \VitisStudio\Tendril\Marketing\Conversions\ConversionBuilder // from tendril-marketing
```

Registered as `Macroable` macros by `ProductServiceProvider` and `MarketingServiceProvider`
respectively — only available once those packages are installed. `Tendril::hasMacro('feature')`
/ `Tendril::hasMacro('conversion')` let host code check before using them (as the workbench does
in `workbench/app/Providers/WorkbenchServiceProvider.php`).

## Contracts

All under `packages/core/src/Contracts/` unless noted. Bind your own implementation in a service
provider, or point `tendril.resolvers.*` at a class name for the three resolver contracts.

| Contract | Method(s) | Default implementation |
|---|---|---|
| `ActorResolver` | `resolve(): ?ActorRef` | `AuthActorResolver` — checks `tendril.resolvers.guards` in order. If it throws, the event or page view is still recorded without an actor and an `IngestionFailed` event is dispatched with stage `actor_resolve` (`Recorder::actor()`, `RequestLifecycle::finish()`). |
| `TenantResolver` | `resolve(): ?TenantRef` | `NullTenantResolver` — always `null`. Throwing behaves like `ActorResolver`: the fact is kept, tenant-less, stage `tenant_resolve`. |
| `ConsentResolver` | `hasAnalyticsConsent(Request $request): bool` | `DenyConsentResolver` — always `false`. Throwing is treated as "no consent" and reported via `IngestionFailed` stage `consent` (`SessionManager::consent()`). |
| `TrackingDecision` | `decide(AnalyticsContext $context): Decision` | None bound; `tendril.decisions` lists implementations evaluated in order. If a decision hook throws, `DecisionPipeline` drops that event (the privacy-safe default, since the hook may have meant to drop or redact it) and dispatches `IngestionFailed` with stage `decision` (`packages/core/src/Decisions/DecisionPipeline.php`). |
| `Transport` | `send(Batch $batch): void` | Selected by `tendril.transport.driver` (`sync`, `queue`, `redis-buffer`, `null`). |
| `BufferStore` | `push`, `pending`, `claim`, `acknowledge`, `inFlight`, `requeueStale` | `RedisBufferStore` (used by the `redis-buffer` transport). |
| `PresenceStore` | `touch`, `members`, `count`, `forget` | `CachePresenceStore` or `RedisPresenceStore`, per `tendril.presence.store`. |
| `SubjectEraser` | `erase(?ActorRef $actor, array $anonymousIds): int` | Packages with identity-bearing derived data tag an implementation `tendril.erasers` (e.g. `VitisStudio\Tendril\Product\Privacy\ActivityEraser`). |
| `MaintenanceAdapter` | `name`, `capabilities`, `prepare`, `pruneBefore`, `describe` | `PortableMaintenance`, `PostgresPartitionMaintenance`, `MySqlPartitionMaintenance`, or `TimescaleMaintenance` (tendril-timescale), selected by `tendril.storage.adapter`. |
| `DiagnosticCheck` | `name(): string`, `run(): CheckResult` | Core/product/timescale register their checks via `Diagnostics::register()`. |
| `ConversionDestination` (tendril-destinations) | `name`, `accepts(OutboundConversion): true\|string`, `send(array): array` | `WebhookDestination`, `MetaConversionsApiDestination`, `GoogleAdsDestination`. |

Example: registering a custom `ActorResolver`.

```php
// config/tendril.php
'resolvers' => [
    'actor' => \App\Analytics\TeamMemberActorResolver::class,
],
```

```php
final class TeamMemberActorResolver implements \VitisStudio\Tendril\Core\Contracts\ActorResolver
{
    public function resolve(): ?\VitisStudio\Tendril\Core\Identity\ActorRef
    {
        $user = auth()->user();

        return $user ? \VitisStudio\Tendril\Core\Identity\ActorRef::from($user) : null;
    }
}
```

## The predicate DSL

Implemented in `packages/core/src/Query/` (`Predicate`, `PredicateParser`, `PredicateEvaluator`,
`PredicateCompiler`). A small, portable JSON tree used by funnels, cohorts and conversions.

```
node      := group | condition
group     := {"all": [node, ...]} | {"any": [node, ...]} | {"not": node}
condition := {"field": field, "op": op, "value"?: scalar | [scalar, ...]}
field     := "name" | "source" | "version"
           | "properties.<key>[.<key>...]" | "context.<key>[.<key>...]"
op        := eq | neq | gt | gte | lt | lte | in | not_in
           | contains | starts_with | exists | not_exists
```

Bounds, enforced by `PredicateParser`: max depth 5, max 50 nodes total, `in`/`not_in` lists up to
100 values, string values up to 256 characters. `exists`/`not_exists` only apply to
`properties.*`/`context.*` (not `name`/`source`/`version`). `version` only supports numeric
operators; `name`/`source` only support string operators. A missing field is always `false`
(use `not_exists` to test for absence). `contains`/`starts_with` are case-insensitive.
`PredicateEvaluator` evaluates the identical semantics in PHP; `PredicateCompiler` compiles the
same tree to SQL (`whereRaw`) for PostgreSQL/MySQL JSON querying.

```json
{
  "all": [
    {"field": "properties.plan", "op": "in", "value": ["pro", "team"]},
    {"field": "context.route", "op": "eq", "value": "pricing"},
    {"not": {"field": "properties.trial", "op": "exists"}}
  ]
}
```

```php
use VitisStudio\Tendril\Core\Query\Predicate;

$predicate = Predicate::fromArray([
    'any' => [
        ['field' => 'properties.format', 'op' => 'eq', 'value' => 'csv'],
        ['field' => 'properties.format', 'op' => 'eq', 'value' => 'xlsx'],
    ],
]);

$predicate->matches(['name' => 'report.exported', 'properties' => ['format' => 'csv']]); // true

// Shorthand for a flat AND of conditions:
Predicate::where([['properties.plan', 'eq', 'pro'], ['version', 'gte', 2]]);
```

## Definitions

### Features (tendril-product)

```php
Tendril::feature('reporting')
    ->name('Reporting')
    ->description('Scheduled and ad-hoc report exports.')
    ->events(['report.viewed', 'report.exported'])
    ->routes(['reports.*'])
    ->components(['report-builder@export', 'reports.show@export']);
```

`VitisStudio\Tendril\Product\Features\FeatureBuilder` (`packages/product/src/Features/FeatureBuilder.php`)
stores the definition through `FeatureRegistry`; `FeatureRepository` persists it to
`tendril_features` as JSON and exposes `export()`/`import()` (used by `tendril:features`).

### Funnels (tendril-product)

Funnels are ordered predicates with a conversion window, built from an array (code, JSON import,
or the Filament definition builder) rather than a fluent builder:

```php
use VitisStudio\Tendril\Product\Funnels\FunnelDefinition;
use VitisStudio\Tendril\Product\Funnels\FunnelEngine;
use VitisStudio\Tendril\Core\Query\TenantScope;

$funnel = FunnelDefinition::fromArray(['key' => 'signup', 'name' => 'Signup', 'definition' => [
    'steps' => [
        ['event' => 'page.viewed', 'label' => 'Pricing', 'where' => ['all' => [
            ['field' => 'context.route', 'op' => 'eq', 'value' => 'pricing'],
        ]]],
        ['event' => 'signup.started'],
        ['event' => 'signup.completed', 'where' => ['all' => [
            ['field' => 'properties.plan', 'op' => 'in', 'value' => ['pro', 'team']],
        ]]],
    ],
    'window_seconds' => 3600,
]]);

$result = app(FunnelEngine::class)->evaluate($funnel, $from, $to, TenantScope::all());
// $result->steps (per-step count, from_previous), $result->entered, $result->completed,
// $result->completionRate(), $result->medianSeconds, $result->averageSeconds
```

Funnels need 2–10 steps; the conversion window is measured from the first step
(`packages/product/src/Funnels/FunnelDefinition.php`). `FunnelEngine::evaluate()` takes an
`includeAnonymous` flag to exclude anonymous visitors from the count.

### Cohorts (tendril-product)

Saved predicates over actor behavior, evaluable "as of" any date
(`packages/product/src/Cohorts/CohortDefinition.php`). Condition types: `performed` /
`not_performed` (event, optional `where` predicate, `min_count`, `within_days`), `used_feature`
(feature key, `min_days`, `within_days`), `first_seen` (`within_days`), `actor_type` (`value`).
Conditions combine with `match: "all"` (intersection) or `"any"` (union). Host-model attributes
(plan, role, …) are not queryable — Tendril holds no foreign keys into host tables; track them
as event properties instead.

```php
use VitisStudio\Tendril\Product\Cohorts\CohortDefinition;
use VitisStudio\Tendril\Product\Cohorts\CohortEvaluator;

$exporters = CohortDefinition::fromArray(['key' => 'exporters', 'definition' => ['conditions' => [
    ['type' => 'performed', 'event' => 'report.exported', 'min_count' => 2, 'within_days' => 7],
]]]);

app(CohortEvaluator::class)->members($exporters, $asOf, TenantScope::all()); // ["App\Models\User|1" => ...]
app(CohortEvaluator::class)->size($exporters, $asOf, TenantScope::all());
```

### Conversions (tendril-marketing)

```php
Tendril::conversion('purchase')
    ->name('Purchase')
    ->event('order.completed')
    ->value('properties.revenue')          // property path; pass true as 2nd arg if already minor units
    ->currencyFrom('properties.currency'); // or ->currency('USD') for a fixed currency

Tendril::conversion('consulting-lead')
    ->name('Consulting lead')
    ->event('lead.created')
    ->where([['properties.service', 'eq', 'consulting']]);
```

`VitisStudio\Tendril\Marketing\Conversions\ConversionBuilder` stores the definition through
`ConversionRegistry`; `ConversionRepository` persists it to `tendril_conversions` and exposes
`export()`/`import()` (used by `tendril:conversions`). Revenue is normalized to integer minor
units via `VitisStudio\Tendril\Marketing\Money\Money::toMinor()`.

## Laravel events

All under `packages/core/src/Events/`, dispatched through `FailOpen::dispatch()` so a listener
failure never breaks capture.

| Event | Fields | Dispatched when |
|---|---|---|
| `BatchPersisted` | `batchId`, `inserted`, `duplicates`, `failed` | A transport finishes writing a batch. |
| `IngestionFailed` | `stage`, `message`, `events`, `exception` | Capture, hand-off, or persistence fails anywhere in the pipeline. |
| `EventRejected` | `name`, `reason` | `EventNormalizer` rejects an event (bad name, oversized payload, clock skew). |
| `EventDropped` | `name`, `reason` | A `TrackingDecision` drops/samples an event, or the buffer overflows. |
| `ErasureCompleted` | `erasureId`, `counts` | `EraseSubject` finishes an erasure. |
| `HealthThresholdExceeded` | `result` (a `CheckResult`) | `tendril:doctor --alert` (or a scheduled health run) finds a non-OK check. |

## Testing helpers

```php
$fake = Tendril::fake(); // RecordingTransport, packages/core/src/Testing/RecordingTransport.php

Tendril::track('report.exported', ['format' => 'csv']);

$fake->assertTracked('report.exported', fn (array $event) => $event['properties']['format'] === 'csv');
$fake->assertTracked('report.exported', times: 1);
$fake->assertNotTracked('report.viewed');
$fake->assertNothingTracked();
$fake->events('report.exported'); // list<array> raw normalized event rows
$fake->batches();                  // list<Batch>
```

`Tendril::fake()` swaps the transport for `recording`, forces `tendril.enabled = true`, and
binds the fake as `RecordingTransport::class` for direct resolution. `RecordingTransport`'s
`assert*()` methods use PHPUnit's `Assert` class directly, which is why `tendril-core` lists
`phpunit/phpunit` under `suggest` in `packages/core/composer.json` — it is only required if the
host application calls these assertions in its own tests. Test helpers in
`tests/Pest.php` (`writeEvent()`, `storedEvents()`, `actor()`, `aggregate()`, `onlyOn()`) are
internal to the monorepo's own test suite, not a published package API.
