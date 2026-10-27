# 0002 — Monorepo split and versioning

## Status

Accepted. Implemented as recorded.

## Context

Six packages need to be developed together (shared tests, a shared workbench, one CI run) but
installed independently via Composer, each as its own Packagist package with its own read-only
Git history. The repository needed a split tool and a versioning policy decided before Phase 0
work began (`plan.md`, "Open decisions to record as ADRs ... Monorepo split tooling and
changelog strategy").

## Decision

Develop in one monorepo (`packages/*`, path repositories in the root `composer.json` with
`"symlink": true` and `"1.x-dev"` versions). On every push to `main` and on `v*` tags, split each
package directory to its own `vitisstudio/<package>` repository with
`danharrin/monorepo-split-github-action@v2.3.0` (`.github/workflows/split.yml`), preserving
history per package. All six packages are versioned in lockstep: a tag on the monorepo (`v1.2.0`)
is pushed as the same tag to every split repository in the same run, so
`vitisstudio/tendril-core:^1.2` and `vitisstudio/tendril-product:^1.2` are always built and
tested against each other.

## Consequences

A host application's `composer.json` can pin any subset of packages to the same minor version
with no compatibility matrix to track — they were tested together at that version. The cost is
that a change to only one package still bumps every package's version number; this is accepted
as the simpler alternative to independent versioning plus a cross-package compatibility matrix.
The split workflow requires a `MONOREPO_SPLIT_TOKEN` secret with push access to each target
repository; this is infrastructure outside the monorepo and not exercised by the test suite.
