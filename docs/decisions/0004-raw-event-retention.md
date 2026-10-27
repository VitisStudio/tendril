# 0004 — Raw-event retention default and the "forever" opt-in

## Status

Accepted. Implemented as recorded.

## Context

Raw events are the only non-backfillable data in Tendril (`plan.md` principle 1: "events are
append-only facts; privacy erasure is the only sanctioned mutation"), but keeping them forever
by default risks unbounded storage growth that an operator did not consciously choose
(`plan.md`, "Open decisions ... Default raw-event retention, and whether 'forever' requires an
explicit opt-in").

## Decision

`tendril.retention.raw_days` defaults to 395 (`env('TENDRIL_RAW_RETENTION_DAYS', 395)`,
`packages/core/config/tendril.php`) — just over a year, enough for year-over-year comparisons.
Setting it to `null` keeps raw events forever, but `tendril:doctor`'s `retention` check
(`packages/core/src/Diagnostics/CoreChecks.php`) warns unless
`tendril.retention.allow_forever` is explicitly `true`, so "forever" is always a conscious
choice, never a silent default. `tendril:prune` (`VitisStudio\Tendril\Core\Storage\Pruner`) can
optionally archive each day to gzip NDJSON on a configured filesystem disk
(`tendril.retention.archive.*`, written by `VitisStudio\Tendril\Core\Storage\Archiver`) before
deleting it, with archive progress tracked in `tendril_state` so re-runs only archive new days.

## Consequences

An operator who never touches retention config gets raw events pruned after ~13 months and no
warning; an operator who sets `raw_days = null` without also setting `allow_forever = true` gets
a visible, actionable warning on every `tendril:doctor` run instead of silently unbounded growth.
Before deleting, the pruned horizon is recorded in `tendril_state` under
`retention.raw_pruned_before` so `tendril:aggregate` never attempts to rebuild a bucket whose
raw facts are already gone (`Pruner::run()`).
