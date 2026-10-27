# 0005 — Campaign change mid-session starts a new session

## Status

Accepted. Implemented as recorded.

## Context

A visitor can arrive, browse, leave, and come back within the same inactivity window carrying
different campaign parameters (e.g. they clicked a second ad for the same site before the first
session timed out). The question was whether that should start a new analytics session, or only
record a new touchpoint while keeping the session running (`plan.md`, "Open decisions ... Whether
campaign change mid-session starts a new session or only a new touchpoint").

## Decision

A new arrival with different campaign parameters starts a new session, and this is configurable:
`tendril.sessions.new_on_campaign_change` (default `true`). The comparison is a fingerprint of
UTM fields and click-ID keys (`Acquisition::fingerprint()`,
`packages/core/src/Acquisition/Acquisition.php`), compared against the session's stored
`campaignFingerprint` in `SessionManager::resolve()`
(`packages/core/src/Sessions/SessionManager.php`). When it starts a new session, a new
touchpoint row is written for that session's acquisition snapshot
(`RequestLifecycle::resolveSession()`, `packages/core/src/Http/RequestLifecycle.php`).

## Consequences

Campaign reporting attributes each session to the campaign that actually brought the visitor to
that visit, rather than the first campaign that ever touched them in a long browsing window. A
host that wants campaign changes to only add a touchpoint without resetting session metrics
(page-view counts, duration) can set `new_on_campaign_change = false`; sessions will then run
their normal inactivity/hard-cap lifecycle regardless of campaign parameter changes, and the
acquisition snapshot on the open session is left as originally captured — only a new session
start writes a new touchpoint.
