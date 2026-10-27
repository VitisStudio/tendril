# 0008 — Native partitioning is opt-in, decided before the first migration

## Status

Accepted. Implemented as recorded.

## Context

`tendril_events` is the table most likely to need partitioning at scale, but PostgreSQL
declarative partitioning and MySQL `RANGE COLUMNS` partitioning both require the partition key to
participate in every unique constraint, and retrofitting a partitioned shape onto a live,
populated table is a heavy operation (full rewrite, new constraints). The question was whether
1.0 should ship native partition management at all, or leave it entirely to the host DBA
(`plan.md`, "Open decisions ... Whether 1.0 ships native partition management or leaves it to the
host DBA").

## Decision

Ship native partitioning, but as an opt-in flag that must be set before the first migration runs:
`tendril.database.partitioned` (default `false`). The events migration
(`packages/core/database/migrations/2026_09_30_000001_create_tendril_events_table.php`) branches
at creation time: on PostgreSQL it creates the table with `PARTITION BY RANGE (occurred_at)` and
a default partition; on MySQL it adds `PARTITION BY RANGE COLUMNS(occurred_at)` with a single
`p_future` partition; either way, the unique key on `dedupe_key` changes to a composite
`(dedupe_key, occurred_at)`, because both engines require the partition column in every unique
index. `tendril:partitions` (backed by `MaintenanceAdapter` implementations selected via
`tendril.storage.adapter`) creates partitions/chunks ahead of time
(`tendril.storage.partitions_ahead_months`). `tendril-timescale` is a separate, equally opt-in
path for PostgreSQL installations that want TimescaleDB's hypertable chunking and compression
instead of native partitioning.

## Consequences

A host that never sets `tendril.database.partitioned` gets a plain table with a simple unique
key and never has to think about partition maintenance. A host that sets it before migrating gets
native partitions and must run `tendril:partitions` on a schedule (recommended monthly in
`docs/operations.md`) or risk inserts failing once the prepared partition range is exhausted.
Changing the flag after the first migration is not supported — the table shape and unique key are
fixed at creation time; moving between partitioned and unpartitioned requires a manual table
rebuild outside the package (the same class of operation `tendril-timescale`'s `--force`
conversion performs for hypertables).

The composite unique key also has a known gap: a resend of the same event (same `dedupe_key`)
can be stored twice if its `occurred_at` differs between attempts — for example, a timestamp
rewritten by `tendril.limits.past_policy = clamp` to "now" at two different moments, or a beacon
client timestamp that drifted outside `tendril.beacon.max_clock_drift_seconds` on one attempt but
not the other. The unpartitioned unique key on `dedupe_key` alone does not have this gap, since it
does not include `occurred_at`.
