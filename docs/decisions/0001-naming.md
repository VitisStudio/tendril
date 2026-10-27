# 0001 — Naming

## Status

Accepted. Implemented as recorded.

## Context

The product needed a Composer vendor, package names, a PHP namespace, a facade name, config
file names, a database table prefix and an Artisan command namespace that would not collide with
common Laravel ecosystem packages — in particular `spatie/laravel-analytics` (an `Analytics`
facade already exists in that space), Laravel Pennant (`Feature`), and Laravel's own `Context`.

## Decision

- Composer vendor: `vitisstudio` (the Packagist account), packages
  `vitisstudio/tendril-{core,product,marketing,filament,timescale,destinations}`.
- PHP namespace: `VitisStudio\Tendril\{Core,Product,Marketing,Filament,Timescale,Destinations}`.
- Facade: `Tendril` (`VitisStudio\Tendril\Core\Facades\Tendril`), aliased by each package's
  `extra.laravel.aliases` in `composer.json`.
- Config files: `config/tendril.php` (core), `config/tendril-product.php`,
  `config/tendril-marketing.php`, `config/tendril-timescale.php`,
  `config/tendril-destinations.php`.
- Database table prefix: `tendril_` (`VitisStudio\Tendril\Core\Support\Tables`).
- Artisan namespace: `tendril:` (every command's `$signature` in `packages/*/src/Commands/`).

## Consequences

Every package, class and config key in the codebase follows this scheme with no exceptions
found during review — `composer.json` in each package, `Tables::core()`
(`packages/core/src/Support/Tables.php`), and every `$signature` under `packages/*/src/Commands/`
match. A host application can require any subset of the six packages without a naming collision
with Pennant's `Feature` facade or `spatie/laravel-analytics`'s `Analytics` facade, since neither
name is used.
