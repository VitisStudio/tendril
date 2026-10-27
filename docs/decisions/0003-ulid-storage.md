# 0003 — ULIDs stored as CHAR(26)

## Status

Accepted. Implemented as recorded.

## Context

Every identity-bearing row (`tendril_events.id`, `tendril_sessions.id`, `tendril_aliases.id`,
`tendril_erasures.id`, `tendril_dead_letters.id`, feature/funnel/cohort/conversion rows' natural
keys are separate, but these primary keys) needed a sortable, globally unique, non-sequential id.
The choice was between ULIDs stored as their canonical 26-character Crockford base32 string
(`CHAR(26)`) or packed into 16 raw bytes (`BINARY(16)`) for a smaller index at scale
(`plan.md`, "ULID storage type: CHAR(26) vs BINARY(16) — affects index size at year two").

## Decision

Store ULIDs as `CHAR(26)`. Every primary/foreign-shaped id column in every core migration uses
`$table->char('id', 26)` or `$table->char(..., 26)` for a referencing column (e.g. `session_id`,
`parent_id`) — see `packages/core/database/migrations/2026_09_30_000001_create_tendril_events_table.php`
and `2026_09_30_000002_create_tendril_sessions_table.php`. IDs are generated with
`Illuminate\Support\Str::ulid()` and compared/sorted as plain strings throughout the codebase
(e.g. `EraseSubject::rewrite()` orders by `id`, `Archiver::archiveDay()` chunks by `id >
$lastId`).

## Consequences

IDs are human-readable in logs, query results and `tendril:inspect` output, and no application
code has to encode/decode a binary column to use them. The cost — a larger index than
`BINARY(16)` at high event volume — was accepted deliberately; `docs/performance.md` (written
separately from measured numbers) is where that cost would be quantified against the load-test
dataset. No `BINARY(16)` code path exists anywhere in the codebase; this was a one-time choice,
not a configurable option.
