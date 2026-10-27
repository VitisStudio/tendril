# 0006 — Client beacon lives in tendril-core, off by default

## Status

Accepted. Implemented as recorded.

## Context

Some facts (outbound link clicks, scroll/engagement signals, page views on fully static/cached
pages that never hit PHP) are only observable client-side. The question was whether a beacon
endpoint and script belonged in `tendril-core` or a separate package, given core's principle of
staying free of anything not strictly required for server-side capture (`plan.md`, "Open
decisions ... Whether the client beacon lives in core or its own package").

## Decision

The beacon lives in `tendril-core`: routes, controller and script are all under
`packages/core/src/Http/Controllers/{CollectController,BeaconScriptController}.php` and
`packages/core/resources/js/tendril-beacon.js`, registered by
`TendrilServiceProvider::registerBeacon()`. It is off by default
(`tendril.beacon.enabled`, `env('TENDRIL_BEACON', false)`). When enabled, it registers
`POST /tendril/collect` and `GET /tendril/beacon.js` (prefix configurable via
`tendril.beacon.prefix`). The collect endpoint: checks the `Origin`/`Referer` host against the
app's own host plus `tendril.beacon.allowed_origins` (`CollectController::originAllowed()`),
is rate-limited per session (`RateLimiter::for('tendril-beacon', ...)`, 120/minute by default),
only accepts a client timestamp within `tendril.beacon.max_clock_drift_seconds` (default 600 —
10 minutes) of server time before falling back to server time, is excluded from CSRF
verification (it is a `sendBeacon`/`fetch` endpoint with its own origin check instead), and
dedupes on the client-supplied ULID via `DedupeKey::beacon()`. Identity (anonymous id, session,
consent) is always resolved server-side from the session/cookie, never trusted from the payload.

## Consequences

A host only pays for the beacon (an extra route, an extra controller class) when it turns it on;
nothing in the beacon code path runs for installations that never enable it. Keeping it in core
rather than a separate package means the beacon shares `Recorder`/`EventNormalizer`/
`TrackingDecision` with every other capture path instead of re-implementing validation and
privacy scrubbing — a beacon event goes through exactly the same bounds and policy as a
server-recorded one.
