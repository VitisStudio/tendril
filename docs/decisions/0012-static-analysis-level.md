# 0012 — Larastan level 8, not max

## Status

Accepted, as of the 2026-10-01 validation pass.

## Context

Larastan (PHPStan with Laravel-specific rules) supports levels up to `max`. Higher levels catch
more, but a package built heavily on raw query-builder rows (rollup and activity table reads,
JSON column decoding) accumulates a specific, low-value class of report at `max`: "cannot cast
mixed" on values pulled from `stdClass` query results, where the actual type is already known
from the column definition but PHPStan cannot infer it statically.

## Decision

`phpstan.neon.dist` pins `level: 8`. Level `max` was evaluated on 2026-10-01 and rejected: it
added approximately 450 "cannot cast mixed" reports on query-builder row values, which the
docblock in `phpstan.neon.dist` describes as "the expected shape of a package built on raw
rollup/activity tables." Level 8 still catches nullability errors, undefined properties and wrong
method signatures — the error classes that matter for this codebase — without that noise. Four
narrow, individually commented `ignoreErrors` entries remain even at level 8:

1. Dynamic SQL fragments (`JsonSql`, grammar `wrap()`) assembled from validated identifiers and
   bound parameters are never PHPStan's `literal-string` type, even though every path segment is
   checked against `JsonSql::KEY_PATTERN` before being embedded.
2. `Illuminate\Database\Query\Expression`'s generic `TValue` parameter versus the concrete
   `float|int|literal-string` PHPStan expects.
3. `VitisStudio\Tendril\Core\Support\Database`'s use of Laravel's own ungenericized connection
   types (`missingType.generics`).
4. Laravel's `PhpRedisConnection::lrem()` normalizes its argument order to match Predis's
   `(key, count, value)`, which disagrees with the `@mixin \Redis` signature PHPStan sees.

## Consequences

`vendor/bin/phpstan analyse` reports zero errors at level 8 as of the 2026-10-01 validation run.
Raising the level again in the future (to catch a new error class) would require either
re-evaluating the ~450 query-builder reports or adding broader suppressions than the four narrow
ones currently in place; this ADR exists so that trade-off is made deliberately next time, not
silently.
