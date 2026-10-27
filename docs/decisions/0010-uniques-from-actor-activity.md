# 0010 — Unique-actor counts only ever come from actor activity, never rollups

## Status

Accepted. Implemented as recorded.

## Context

`tendril_rollups` stores pre-aggregated counts/sums per hour and day. It is tempting to answer
"how many distinct users did X" from a rollup, but counts aggregated across buckets cannot be
de-duplicated after the fact — summing daily active counts across seven days does not give a
correct weekly active count, since the same actor may appear in several days. `plan.md` principle
5 states this directly: "Uniques never come from rollups — DAU/WAU/MAU use the actor-activity
table."

## Decision

`tendril_actor_activity` (`packages/product/database/migrations/2026_09_30_000100_create_tendril_rollup_tables.php`)
is a separate table — one row per `(tenant, actor, date, kind, subject)` — and is the only table
every uniques-shaped query reads: `ProductMetrics` (DAU/WAU/MAU), `RetentionCalculator` (weekly
retention matrices), `CohortEvaluator` (cohort membership and size), and feature uniques.
`ActivityProcessor` (`packages/product/src/Aggregation/Processors/ActivityProcessor.php`)
populates it from raw events during the same hourly aggregation pass that populates
`tendril_rollups`, but as a distinct write with its own unique index rather than a derivative of
the rollup numbers. `tendril_rollups` is used only for counts/sums where no de-duplication across
time is needed (event volume, feature trend charts).

## Consequences

Every distinct-actor metric in the codebase is correct by construction — there is no code path
that attempts to combine rollup counts into a unique count, so the error class "summed daily
actives reported as weekly actives" cannot occur. The cost is an extra table and an extra write
per aggregated hour; `DerivedDataCorrectnessTest`
(`tests/Product/DerivedDataCorrectnessTest.php`) recomputes metrics directly from raw events on a
generated dataset and asserts the rollup/activity answers match exactly, specifically to catch
any future regression of this guarantee.
