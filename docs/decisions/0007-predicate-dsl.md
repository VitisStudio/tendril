# 0007 — The predicate DSL grammar

## Status

Accepted. Implemented as recorded.

## Context

Funnels, cohorts and conversions all need to filter events by property/context values, and the
same filter needs to run three ways: as SQL against PostgreSQL and MySQL (for aggregation and
reporting), in plain PHP (for the funnel/cohort engines reading raw events), and as JSON (for
storage in `tendril_funnels`/`tendril_cohorts`/`tendril_conversions` and for the Filament
definition builder). The grammar needed to be small enough to keep all three implementations in
sync and portable across both database engines (`plan.md`, "Open decisions ... Predicate/query
DSL grammar").

## Decision

A JSON tree of groups (`all`/`any`/`not`) and conditions (`field`, `op`, optional `value`), with
fields limited to `name`, `source`, `version` and dotted paths under `properties.*`/`context.*`,
and operators limited to `eq neq gt gte lt lte in not_in contains starts_with exists not_exists`
(`packages/core/src/Query/Predicate.php`). `PredicateParser::normalize()` enforces bounds (max
depth 5, max 50 nodes, `in`/`not_in` lists up to 100 values, strings up to 256 characters) and
type rules (`version` numeric-only, `name`/`source` string-only, `exists`/`not_exists` only on
`properties.*`/`context.*`) at construction time, so an invalid predicate never reaches storage.
`PredicateEvaluator::evaluate()` (PHP) and `PredicateCompiler::compile()` (SQL) implement
identical semantics: a missing field is always `false` (never an error, never "any value
matches"), `contains`/`starts_with` are case-insensitive, and type mismatches between an actual
value and an expected value make `eq`/`neq` both `false` rather than throwing.

## Consequences

The same JSON definition evaluates identically whether it runs historically over raw events in
PHP or as a `whereRaw` fragment in a rollup query, which is what makes "evaluable historically"
(`plan.md` principle 2) possible without maintaining two filter languages. The known SQLite
caveat — `contains`/`starts_with` case-folding is ASCII-only on SQLite, unlike PostgreSQL/MySQL's
full Unicode case folding — only affects local development, since SQLite is not a supported
production driver (see `docs/testing.md`).
