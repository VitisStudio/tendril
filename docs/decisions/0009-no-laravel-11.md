# 0009 — Laravel 11 is not supported

## Status

Accepted. Implemented as recorded.

## Context

Filament 5 permits Laravel 11.28+, so supporting Laravel 11 was technically possible. The
question was whether to carry that support given Tendril's other floor requirements — PHP 8.4
and Livewire 4 — and the cost of testing a third Laravel major (`plan.md`, "Open decisions ...
Whether Laravel 11 is worth supporting given Filament 5 permits 11.28+ (Phase 0 — currently:
no)").

## Decision

Support only Laravel 12 and 13. Every package's `composer.json` constrains
`illuminate/*: ^12.0|^13.0` (e.g. `packages/core/composer.json`), and the CI matrix
(`.github/workflows/tests.yml`) tests `laravel: ['12.*', '13.*']` paired with
`testbench: ['10.*', '11.*']` respectively — there is no Laravel 11 / Testbench 9 leg.

## Consequences

The package can rely on Laravel 12+ APIs without a compatibility shim, and the test matrix stays
at two Laravel majors instead of three. A host still running Laravel 11 cannot install any
Tendril package until it upgrades; this is a deliberate floor, not an oversight, and is listed in
the `README.md` requirements and `plan.md` runtime floors.
