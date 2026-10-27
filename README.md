# Tendril

![Tendril](art/hero.png)

Tendril is first-party product and marketing analytics for Laravel. It captures page views,
auth events, Livewire actions and whatever business facts you record, keyed to sessions,
anonymous visitors and (optionally) tenants, and stores them in your own PostgreSQL or MySQL
database. Capture is fail-open: a broken transport, a down Redis, or a bad config never breaks
a request.

On top of that raw event log, Tendril builds product analytics (features, funnels, cohorts,
retention, journeys), marketing reporting (UTM/click-ID acquisition, touchpoints, attribution,
conversions, campaigns), a Filament 5 plugin for browsing it all, an optional TimescaleDB
accelerator, and outbound conversion destinations (webhook, Meta Conversions API, Google Ads).
Everything is privacy-safe by default: cookieless identity, no IP storage, and click IDs that
are off until you opt in.

## Packages

| Package | Namespace | Purpose |
|---|---|---|
| `vitisstudio/tendril-core` | `VitisStudio\Tendril\Core` | Event capture, sessions, identity, transports, privacy, diagnostics. No dependency on the other packages. |
| `vitisstudio/tendril-product` | `VitisStudio\Tendril\Product` | Feature definitions, rollups, actor activity, funnels, cohorts, retention, journeys. |
| `vitisstudio/tendril-marketing` | `VitisStudio\Tendril\Marketing` | Conversion definitions, attribution, revenue, campaigns, conversion paths. |
| `vitisstudio/tendril-filament` | `VitisStudio\Tendril\Filament` | Filament 5 plugin: pages and widgets over rollups/activity. |
| `vitisstudio/tendril-timescale` | `VitisStudio\Tendril\Timescale` | Optional TimescaleDB hypertable/compression/capability detection. |
| `vitisstudio/tendril-destinations` | `VitisStudio\Tendril\Destinations` | Idempotent delivery of conversions to webhooks, Meta and Google Ads. |

## Requirements

- PHP 8.4, Laravel 12 or 13
- PostgreSQL 14+ or MySQL 8.0+ (both are first-class; SQLite is for local development only)
- Livewire 4 (for server-side action capture) and Filament 5 (for the reporting UI), both optional
- Redis, only if you use the `redis-buffer` transport or the Redis presence store

See `packages/core/composer.json` and the other package `composer.json` files for exact
constraints.

## Quick start

```bash
composer require vitisstudio/tendril-core vitisstudio/tendril-product
php artisan vendor:publish --tag=tendril-config
php artisan migrate
```

```php
// bootstrap/app.php or a service provider: register the capture middleware
// group (tendril.http.middleware_groups defaults to ["web"], so this step
// is often already done for you by the package's own boot sequence).

// Anywhere in the app:
use VitisStudio\Tendril\Core\Facades\Tendril;

Tendril::track('order.completed', ['revenue' => '49.00', 'currency' => 'USD']);
```

`tendril:doctor` checks migrations, transport, retention and privacy posture once installed.

## Documentation

- [`docs/installation.md`](docs/installation.md) — per-package install steps, middleware, Livewire, Filament, queue/Redis prerequisites
- [`docs/configuration.md`](docs/configuration.md) — every config key, grouped by package, with privacy defaults
- [`docs/api.md`](docs/api.md) — facade methods, contracts, the predicate DSL, events, testing helpers
- [`docs/architecture.md`](docs/architecture.md) — package layout, event envelope, request lifecycle, sessions/identity, aggregation, attribution, erasure
- [`docs/operations.md`](docs/operations.md) — every `tendril:*` command, scheduler recommendations, health checks
- [`docs/testing.md`](docs/testing.md) — running the suite against PostgreSQL and MySQL
- [`docs/decisions/`](docs/decisions/README.md) — architecture decision records
# tendril
