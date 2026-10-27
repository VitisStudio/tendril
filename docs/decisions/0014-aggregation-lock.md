# 0014 — Cache lock around `tendril:aggregate`

## Status

Accepted. Implemented.

## Context

`Aggregator::run()` processes dirty hour buckets by deleting and re-inserting each bucket's
rollup/activity rows inside a transaction (idempotent by design — see ADR 0010's "never
incremented" rebuild model). That idempotency assumes only one process processes a given bucket
at a time. If two `tendril:aggregate` invocations overlap — a manual run while the scheduled one
is still in progress, or two scheduler nodes racing after a deploy — both can claim and recompute
the same hour bucket concurrently, which produces duplicate-key failures on the delete-then-insert
step, or, depending on timing, a bucket left doubled or silently dropped.

## Decision

`Aggregator::run()` takes an atomic cache lock named `tendril:aggregate` before doing any work
(`Aggregator::lock()`, `packages/product/src/Aggregation/Aggregator.php`), using
`tendril-product.lock.store` (default: the application's default cache store) and
`tendril-product.lock.seconds` (default 900; `0` disables the lock entirely). The lock requires a
store implementing `Illuminate\Contracts\Cache\LockProvider`; a store that does not (and no
atomic-lock-capable store resolved) runs unlocked rather than failing the command. If the lock is
already held, `run()` returns immediately with `AggregationResult::$locked = true`, `drain()`
stops its loop on a locked result, and `tendril:aggregate` prints "Another aggregation run holds
the lock; nothing was recomputed." and exits successfully (not a failure).

## Consequences

Overlapping invocations are now a safe no-op instead of a race. The cost is a dependency on a
lock-capable cache store for installations that run `tendril:aggregate` from more than one
process or host; the `database` cache store needs its `cache_locks` table (Laravel's default
cache migrations), and stores without lock support (e.g. a plain `array` store shared across
processes is not actually shared, or a custom store without `LockProvider`) silently run without
protection — `tendril:doctor` does not currently check for this, so it is a configuration
responsibility, not an enforced guarantee. The lock does not replace `withoutOverlapping()` on
the scheduler entry so much as make it redundant for this specific command; both together are
still correct and recommended (see `docs/operations.md`).
