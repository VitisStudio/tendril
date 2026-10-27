# 0011 — Erasure assigns fresh, unlinkable pseudonyms per request

## Status

Accepted. Implemented as recorded.

## Context

Privacy erasure (GDPR/CCPA-style "right to be forgotten") needs to remove identity from an
actor's or anonymous visitor's historical events without deleting the events themselves — the
aggregate counts and trends they contributed to must remain correct. The question was how to
rewrite `actor_id`/`anonymous_id` so the rows become unlinkable to the original person while
staying internally consistent with each other (so an aggregation rebuild still counts them as one
"actor" worth of activity, not zero or many).

## Decision

Every erasure request generates its own fresh pseudonym pair at request time —
`'erased:'.strtolower(Str::ulid())` for both the actor and anonymous slots — stored on the
`tendril_erasures` row (`ErasureManager::request()`,
`packages/core/src/Privacy/ErasureManager.php`). `EraseSubject`
(`packages/core/src/Privacy/EraseSubject.php`) rewrites every matching event's `actor_id` (or
`anonymous_id`) to that erasure's pseudonym, strips configured PII property paths
(`tendril.privacy.erasure_strip_paths`), marks the affected hours dirty for rollup/activity
rebuild, deletes the alias/session/touchpoint rows outright, and invokes every `SubjectEraser`
tagged `tendril.erasers` so other packages (e.g. `tendril-product`'s `ActivityEraser`) can clean
up their own identity-bearing derived data. The job processes a bounded number of chunks per
run and re-dispatches itself if more remain, which is naturally resumable: once a chunk is
rewritten it no longer matches the original query, so there is no separate progress cursor to
maintain.

## Consequences

Two different erasures never collide on the same pseudonym, and nothing in the pseudonym itself
links back to the original actor or anonymous id — it carries no information beyond "this was
erased." Derived data recomputed after an erasure still counts the erased identity as one
consistent actor for historical totals (the row count is unchanged), but can no longer be
attributed to the original person, nor merged back with their pre-erasure history. Audit records
(`tendril_erasures`, `tendril_audit_log`) use a one-way fingerprint of the original identity
(`Audit::fingerprint()`) rather than the identity itself, so the audit trail proves an erasure
happened without retaining what was erased.
