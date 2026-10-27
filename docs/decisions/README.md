# Architecture decision records

Each record documents one decision: Status / Context / Decision / Consequences. Where the
implementation differs from the originally recorded decision, the record says so and documents
the code as built.

| ADR | Decision |
|---|---|
| [0001](0001-naming.md) | Naming: vendor, packages, namespace, facade, config, table prefix |
| [0002](0002-monorepo-split-and-versioning.md) | Monorepo development, split-to-per-package release, lockstep versioning |
| [0003](0003-ulid-storage.md) | ULIDs stored as `CHAR(26)` |
| [0004](0004-raw-event-retention.md) | Raw-event retention default and the "forever" opt-in |
| [0005](0005-session-split-on-campaign-change.md) | Campaign change mid-session starts a new session |
| [0006](0006-beacon-in-core.md) | Client beacon lives in `tendril-core`, off by default |
| [0007](0007-predicate-dsl.md) | The predicate DSL grammar |
| [0008](0008-native-partitioning-opt-in.md) | Native partitioning is opt-in, decided before first migration |
| [0009](0009-no-laravel-11.md) | Laravel 11 is not supported |
| [0010](0010-uniques-from-actor-activity.md) | Unique-actor counts only ever come from actor activity, never rollups |
| [0011](0011-erasure-pseudonyms.md) | Erasure assigns fresh, unlinkable pseudonyms per request |
| [0012](0012-static-analysis-level.md) | Larastan level 8, not `max` |
| [0013](0013-consent-revocation-rotates-identity.md) | Consent revocation rotates the anonymous identity |
| [0014](0014-aggregation-lock.md) | Cache lock around `tendril:aggregate` prevents overlapping runs |
