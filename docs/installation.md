# Installation

Every Tendril package is a Laravel package with auto-discovery (`extra.laravel.providers` in
each `composer.json`), so requiring a package is enough to register its service provider. No
package registers itself manually in `bootstrap/providers.php`.

## 1. `tendril-core`

Required by every other package. Capture, sessions, identity, transports, privacy and
diagnostics live here; it has no dependency on Filament, Timescale or any vendor SDK
(`packages/core/composer.json`).

```bash
composer require vitisstudio/tendril-core
php artisan vendor:publish --tag=tendril-config
php artisan migrate
```

This publishes `config/tendril.php`. Migrations run automatically on boot when
`tendril.database.run_migrations` is `true` (the default) — see
`packages/core/src/TendrilServiceProvider.php::packageBooted()`. If you prefer to version them
yourself, publish and disable auto-loading:

```bash
php artisan vendor:publish --tag=tendril-migrations
```

and set `'run_migrations' => false` in `config/tendril.php`.

### Middleware

`TendrilServiceProvider::registerMiddleware()` (`packages/core/src/TendrilServiceProvider.php`)
registers a `tendril` route-middleware alias for
`VitisStudio\Tendril\Core\Http\Middleware\TrackRequests` and, on boot, pushes that same
middleware onto every group listed in `tendril.http.middleware_groups` (default `['web']`) via
`Router::pushMiddlewareToGroup()`. For a default Laravel app this means page views, sessions and
acquisition are captured with no manual middleware registration. `TrackRequests` is terminable:
request-scoped work happens in `handle()`, storage writes happen in `terminate()`, after the
response has been sent.

To track a group other than `web`, add it to `tendril.http.middleware_groups`. To track only
specific routes, remove all middleware groups and apply the `tendril` alias directly to a route
or route group.

### Livewire hook registration

If `livewire/livewire` and `Livewire\ComponentHook` are present, `registerLivewire()` calls
`Livewire::componentHook(VitisStudio\Tendril\Core\Livewire\LivewireActionHook::class)` during
boot, gated by `tendril.livewire.enabled` (default `true`). No action is needed in the host app;
mark an action `#[VitisStudio\Tendril\Core\Livewire\Untracked]` to exclude it (named
`wire:poll` handlers in particular, since the hook cannot tell them apart from a real action).

### Beacon assets (optional)

The beacon is off by default (`tendril.beacon.enabled`, env `TENDRIL_BEACON`). When enabled, the
service provider registers `POST /tendril/collect` and `GET /tendril/beacon.js` (prefix
configurable via `tendril.beacon.prefix`). Publish the static script if you want to serve it
yourself instead of through the package route:

```bash
php artisan vendor:publish --tag=tendril-assets
```

### Queue / Redis prerequisites

The default transport is `queue` (`tendril.transport.driver`), which dispatches one job per
request/unit-of-work carrying all of that work's events
(`VitisStudio\Tendril\Core\Transport\QueueTransport`). Run a queue worker; `tendril.transport.queue.connection`
and `.queue` default to your app's queue defaults. The `redis-buffer` transport additionally
needs a reachable Redis (`tendril.transport.redis_buffer.connection`, default connection
`default`) and `predis/predis` or `ext-redis`; drain it with `tendril:drain` (see
`docs/operations.md`). The `sync` transport needs neither a queue nor Redis but writes inline
after the response. `null` disables storage entirely.

The Redis presence store (`tendril.presence.store = redis`) also needs Redis; the `cache`
presence store (the default) uses your app's default cache store, or
`tendril.presence.cache_store` if set.

### Analytics database connection

By default Tendril tables are created on your application's default database connection. Set
`TENDRIL_DB_CONNECTION` (or `tendril.database.connection`) to point Tendril at a separate
connection (a read replica, a dedicated analytics database, etc.) — every query in the package
goes through `VitisStudio\Tendril\Core\Support\Database`, which resolves this connection lazily
on every call. Decide `tendril.database.partitioned` (native partitioning) before running the
first migration: it is baked into the `tendril_events` table shape and its unique key
(`packages/core/database/migrations/2026_09_30_000001_create_tendril_events_table.php`); see
`docs/decisions/0008-native-partitioning-opt-in.md`.

## 2. `tendril-product`

```bash
composer require vitisstudio/tendril-product
php artisan vendor:publish --tag=tendril-product-config
php artisan migrate
```

Publishes `config/tendril-product.php` and the rollup/activity/definition migrations
(`packages/product/database/migrations/`), gated by the same
`tendril.database.run_migrations` flag. Registering this package adds the `feature()` macro to
the `Tendril` facade (`ProductServiceProvider::packageBooted()`), and registers its own
diagnostic checks and aggregation processors.

## 3. `tendril-marketing`

```bash
composer require vitisstudio/tendril-marketing
php artisan vendor:publish --tag=tendril-marketing-config
php artisan migrate
```

Depends on `tendril-core` and `tendril-product`. Publishes `config/tendril-marketing.php` and
the `tendril_conversions` migration, and adds the `conversion()` macro to the `Tendril` facade.

## 4. `tendril-filament`

```bash
composer require vitisstudio/tendril-filament
```

No config file of its own (`packages/filament` has no `config/` directory). Register the plugin
per panel:

```php
use VitisStudio\Tendril\Filament\TendrilPlugin;

$panel->plugin(
    TendrilPlugin::make()
        ->navigationGroup('Analytics')          // default: "Analytics"
        ->authorize(fn ($user) => $user->is_admin)
        ->tenantScopeUsing(fn () => Filament::getTenant())
        ->trackPanelRequests()                   // default: true
        ->pages([...])                            // override the default page set
        ->withoutPages([...]),                    // or exclude specific pages
);
```

(`packages/filament/src/TendrilPlugin.php`). Each panel gets its own plugin instance, so
authorization, tenant scoping and the page set can differ per panel — see
`workbench/app/Providers/Filament/AdminPanelProvider.php` (no tenancy) and
`TeamPanelProvider.php` (`->tenant(Team::class)`, tenant-aware reports with zero extra
configuration) for working examples. `trackPanelRequests(true)` (the default) pushes
`TrackRequests` as persistent Filament panel middleware so Livewire updates from panel pages are
captured too.

Authorization defaults to the `viewTendril` gate (nobody outside the `local` environment until
the host defines it — `TendrilFilamentServiceProvider::packageBooted()`); editing definitions
additionally checks `manageTendril` when the host defines that gate
(`VitisStudio\Tendril\Filament\Support\TendrilFilament::canManage()`). Define these in your own
`AuthServiceProvider`/service provider, e.g.
`Gate::define('viewTendril', fn ($user) => $user->is_admin)` as the workbench does in
`workbench/app/Providers/WorkbenchServiceProvider.php`.

Widgets (`ActivityStats`, `DailyActiveChart`, `EventVolumeChart`, `TopFeaturesChart`,
`FeatureTrendChart`, `ConversionTrendChart`) are registered as plain Livewire components, not
Filament dashboard widgets (`TendrilFilamentServiceProvider::WIDGETS`), so they never appear
anywhere until you place them deliberately, e.g. `@livewire('tendril.widgets.activity-stats')`
or inside a custom Filament page/dashboard.

## 5. `tendril-timescale` (optional)

```bash
composer require vitisstudio/tendril-timescale
php artisan vendor:publish --tag=tendril-timescale-config
```

Only useful on PostgreSQL with the TimescaleDB extension installed. No new migrations of its
own; it converts the existing `tendril_events` table. Run `php artisan tendril:timescale:install
--detect` to see capabilities, then without `--detect` to convert. See
`docs/operations.md` for the full flow and `docs/decisions/0008-native-partitioning-opt-in.md`.

## 6. `tendril-destinations` (optional)

```bash
composer require vitisstudio/tendril-destinations
php artisan vendor:publish --tag=tendril-destinations-config
php artisan migrate
```

Depends on `tendril-core` and `tendril-marketing`. Publishes `config/tendril-destinations.php`
and the `tendril_destination_deliveries` migration. Configure destinations under
`tendril-destinations.destinations` (see `packages/destinations/config/tendril-destinations.php`
for the webhook/Meta/Google Ads examples, all commented out by default) — credentials come from
environment variables only, never from the database. Dispatch with
`php artisan tendril:destinations:dispatch` on a schedule.

## Installing everything at once

The monorepo's own root `composer.json` requires all six packages together for development; a
host application can do the same:

```bash
composer require vitisstudio/tendril-core vitisstudio/tendril-product vitisstudio/tendril-marketing vitisstudio/tendril-filament vitisstudio/tendril-timescale vitisstudio/tendril-destinations
php artisan vendor:publish --tag=tendril-config
php artisan vendor:publish --tag=tendril-product-config
php artisan vendor:publish --tag=tendril-marketing-config
php artisan vendor:publish --tag=tendril-timescale-config
php artisan vendor:publish --tag=tendril-destinations-config
php artisan migrate
```
