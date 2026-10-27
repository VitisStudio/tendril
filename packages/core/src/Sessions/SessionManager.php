<?php

namespace VitisStudio\Tendril\Core\Sessions;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use VitisStudio\Tendril\Core\Acquisition\Acquisition;
use VitisStudio\Tendril\Core\Acquisition\AcquisitionParser;
use VitisStudio\Tendril\Core\Contracts\ConsentResolver;
use VitisStudio\Tendril\Core\Support\FailOpen;

/**
 * Analytics session lifecycle.
 *
 * - Own ULID, separate from the Laravel session id (which rotates on login).
 * - Ends after the inactivity gap (default 30 min) or hard cap (default 24 h).
 * - A new arrival with different campaign parameters starts a new session.
 * - Cookieless mode: the anonymous id lives inside the Laravel session.
 * - Cookie mode: a persistent first-party cookie, only with consent; consent
 *   revocation forgets the cookie and falls back to the session-bound id.
 */
final class SessionManager
{
    public function __construct(private readonly AcquisitionParser $parser) {}

    public function resolve(Request $request, CarbonImmutable $now, ?string $landingRoute, ?Acquisition $acquisition = null): ?SessionResolution
    {
        if (! $request->hasSession()) {
            return null;
        }

        $consent = $this->consent($request);
        $acquisition ??= $this->parser->fromRequest($request, $consent);
        $key = $this->sessionKey();
        $state = SessionState::fromArray($request->session()->get($key));

        [$anonymousId, $cookie] = $this->anonymousId($request, $state, $consent);

        $inactivity = max(1, (int) config('tendril.sessions.inactivity_minutes', 30));
        $hardCap = max($inactivity, (int) config('tendril.sessions.hard_cap_minutes', 1440));

        $campaignChanged = (bool) config('tendril.sessions.new_on_campaign_change', true)
            && $state !== null
            && $acquisition->hasCampaign()
            && $acquisition->fingerprint() !== $state->campaignFingerprint;

        // A rotated anonymous id (consent revoked while a cookie was still
        // presented, or a foreign cookie on this Laravel session) is a new visitor.
        $identityRotated = $state !== null && $state->anonymousId !== $anonymousId;

        if ($state === null || $state->expiredAt($now, $inactivity, $hardCap) || $campaignChanged || $identityRotated) {
            $since = ($state !== null && $state->anonymousId === $anonymousId) ? $state->anonymousSince : $now;

            $state = new SessionState(
                (string) Str::ulid(),
                $now,
                $now,
                $anonymousId,
                $since,
                $acquisition->fingerprint(),
            );

            return new SessionResolution($state, true, $acquisition, $landingRoute, $cookie);
        }

        return new SessionResolution($state->touched($now, $anonymousId), false, Acquisition::none(), null, $cookie);
    }

    /**
     * Persist the state into the Laravel session. Called after the controller
     * ran so it survives session()->invalidate() on logout.
     */
    public function persist(Request $request, SessionState $state): void
    {
        if ($request->hasSession()) {
            $request->session()->put($this->sessionKey(), $state->toArray());
        }
    }

    public function applyCookie(Response $response, SessionResolution $resolution): void
    {
        if ($resolution->cookie === null) {
            return;
        }

        $name = (string) config('tendril.identity.cookie.name', 'tendril_aid');

        if ($resolution->cookie === false) {
            $response->headers->setCookie(Cookie::create($name)->withExpires(1)->withPath((string) config('session.path', '/')));

            return;
        }

        $days = max(1, (int) config('tendril.identity.cookie.lifetime_days', 390));

        $response->headers->setCookie(Cookie::create(
            $name,
            $resolution->state->anonymousId,
            time() + $days * 86400,
            (string) config('session.path', '/'),
            is_string(config('session.domain')) ? config('session.domain') : null,
            (bool) config('session.secure', false) ?: null,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    /**
     * @return array{0: string, 1: ?bool}
     */
    private function anonymousId(Request $request, ?SessionState $state, bool $consent): array
    {
        $mode = (string) config('tendril.identity.mode', 'cookieless');
        $name = (string) config('tendril.identity.cookie.name', 'tendril_aid');
        $existing = $state?->anonymousId;

        if ($mode !== 'cookie') {
            return [$existing ?? (string) Str::ulid(), null];
        }

        $cookieValue = $request->cookies->get($name);
        $cookieValue = is_string($cookieValue) && Str::isUlid($cookieValue) ? $cookieValue : null;

        if (! $consent) {
            // Consent absent or revoked: session-bound identity only. A cookie
            // still presented by the browser is expired, and because the id it
            // carried was persisted without current consent it is not reused.
            $forget = $cookieValue !== null || $request->cookies->has($name);

            return [$forget || $existing === null ? (string) Str::ulid() : $existing, $forget ? false : null];
        }

        // Consent granted: the cookie is the anonymous id; promote the
        // session-bound id when consent is given mid-session for continuity.
        $anonymousId = $cookieValue ?? $existing ?? (string) Str::ulid();

        return [$anonymousId, $cookieValue === null ? true : null];
    }

    private function consent(Request $request): bool
    {
        return (bool) FailOpen::run(fn () => app(ConsentResolver::class)->hasAnalyticsConsent($request), 'consent', false);
    }

    public function hasConsent(Request $request): bool
    {
        return $this->consent($request);
    }

    private function sessionKey(): string
    {
        return (string) config('tendril.sessions.session_key', '_tendril');
    }
}
