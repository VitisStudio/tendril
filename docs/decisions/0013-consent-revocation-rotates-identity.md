# 0013 — Consent revocation rotates the anonymous identity

## Status

Accepted. Implemented during validation (2026-10-01); documented here because it changes
observable behavior from what an earlier pass may have assumed.

## Context

In cookie identity mode (`tendril.identity.mode = cookie`), the anonymous id is a persistent
first-party cookie (`tendril_aid`), written only while `ConsentResolver::hasAnalyticsConsent()`
returns `true`. The open question was what should happen on the *next* request after consent is
revoked, if the browser still presents the old `tendril_aid` cookie (the host application removed
consent in its own UI, but has not necessarily also told the browser to delete the cookie).

## Decision

When consent is absent or revoked and the request still carries a `tendril_aid` cookie,
`SessionManager::anonymousId()` (`packages/core/src/Sessions/SessionManager.php`) does two things
on the response: it expires the cookie (`SessionManager::applyCookie()` sets it to expire
immediately), and it generates a brand-new anonymous id for the session-bound fallback rather
than reusing the id the expiring cookie carried. `SessionManager::resolve()`'s `$identityRotated`
check treats this id change as equivalent to a brand-new visitor: it ends the current analytics
session and starts a new one, even if the visitor is still within the normal inactivity window.

## Consequences

Once consent is revoked, no later event can be linked — even probabilistically, by someone with
database access — to the anonymous id that existed under consent, because that id is never
reused once the cookie carrying it is rejected. The practical effect is a session boundary at the
exact moment consent changes: page-view counts and session duration for that visit are split
across two session rows instead of one continuous session. This is the correct trade-off for the
privacy guarantee it buys, and `tests/Core/` covers the rotation behavior (searching for
`identityRotated`/consent revocation scenarios in `tests/Core/MiddlewareTest.php` and related
tests). Cookieless mode (the default) is unaffected, since it never writes a persistent cookie.
