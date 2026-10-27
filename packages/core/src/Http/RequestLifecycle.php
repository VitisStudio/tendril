<?php

namespace VitisStudio\Tendril\Core\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use VitisStudio\Tendril\Core\Acquisition\Acquisition;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Capture\DedupeKey;
use VitisStudio\Tendril\Core\Capture\Flusher;
use VitisStudio\Tendril\Core\Capture\Recorder;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Contracts\ActorResolver;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;
use VitisStudio\Tendril\Core\Presence\PresenceManager;
use VitisStudio\Tendril\Core\Sessions\SessionManager;
use VitisStudio\Tendril\Core\Sessions\SessionResolution;
use VitisStudio\Tendril\Core\Support\FailOpen;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * Per-request coordinator (scoped binding). Entry: context + session.
 * After the controller: persist session state, cookies. After the response
 * (terminate): page view, session op, presence, flush, context clear.
 */
final class RequestLifecycle
{
    private ?Request $request = null;

    private ?CarbonImmutable $startedAt = null;

    private ?SessionResolution $session = null;

    private bool $terminated = true;

    private bool $sessionDeferred = false;

    public function request(): ?Request
    {
        return $this->terminated ? null : $this->request;
    }

    public function active(): bool
    {
        return ! $this->terminated;
    }

    public function begin(Request $request): void
    {
        $this->request = $request;
        $this->startedAt = Instant::now();
        $this->session = null;
        $this->terminated = false;
        $this->sessionDeferred = false;

        app(Buffer::class)->reset();

        if (! Recorder::enabled()) {
            return;
        }

        FailOpen::run(function () use ($request): void {
            $store = app(ContextStore::class);
            $store->begin(
                RequestClassifier::isLivewireUpdate($request) ? 'livewire' : 'http',
                $this->requestId($request),
                $this->traceId($request),
            );

            $route = RouteNamer::forRequest($request);
            $store->setRoute($route);

            // The beacon endpoint resolves its session from the payload instead.
            if ($route === 'tendril.collect') {
                $this->sessionDeferred = true;

                return;
            }

            $landing = $request->isMethod('GET') && ! RequestClassifier::isLivewireUpdate($request) && ! RequestClassifier::isPrefetch($request)
                ? $route
                : null;

            $this->resolveSession($request, null, $landing);
        }, 'request_begin');
    }

    /**
     * Resolve (or start) the analytics session and publish ids to Context.
     */
    public function resolveSession(Request $request, ?Acquisition $acquisition, ?string $landingRoute): ?SessionResolution
    {
        $now = $this->startedAt ?? Instant::now();
        $resolution = app(SessionManager::class)->resolve($request, $now, $landingRoute, $acquisition);
        $this->session = $resolution;

        if ($resolution === null) {
            return null;
        }

        $state = $resolution->state;
        app(ContextStore::class)->setVisitor($state->id, $state->anonymousId, Instant::serialize($state->anonymousSince));

        $buffer = app(Buffer::class);

        if ($resolution->started) {
            $columns = $resolution->acquisition->toSessionColumns();
            $actor = FailOpen::run(fn () => app(ActorResolver::class)->resolve(), 'actor_resolve');
            $tenant = FailOpen::run(fn () => app(TenantResolver::class)->resolve(), 'tenant_resolve');
            $identity = [
                'actor_type' => $actor?->type,
                'actor_id' => $actor?->id,
                'tenant_type' => $tenant?->type,
                'tenant_id' => $tenant?->id,
            ];

            $buffer->session($state->id, [
                'open' => $columns + $identity + [
                    'anonymous_id' => $state->anonymousId,
                    'started_at' => Instant::serialize($state->startedAt),
                    'landing_route' => $resolution->landingRoute,
                    'environment' => Recorder::environment(),
                ],
                'last_seen_at' => Instant::serialize($now),
            ]);

            if ($resolution->acquisition->hasData()) {
                $effective = $resolution->acquisition->effectiveSourceMedium();

                $buffer->touchpoint($identity + [
                    'session_id' => $state->id,
                    'occurred_at' => Instant::serialize($state->startedAt),
                    'anonymous_id' => $state->anonymousId,
                    'source' => $effective['source'],
                    'medium' => $effective['medium'],
                    'campaign' => $resolution->acquisition->campaign,
                    'content' => $resolution->acquisition->content,
                    'term' => $resolution->acquisition->term,
                    'referrer_host' => $resolution->acquisition->referrerHost,
                    'referrer_class' => $resolution->acquisition->referrerClass,
                    'landing_route' => $resolution->landingRoute,
                    'click_ids' => $resolution->acquisition->clickIds,
                    'environment' => Recorder::environment(),
                ]);
            }
        } else {
            $buffer->session($state->id, ['last_seen_at' => Instant::serialize($now)]);
        }

        return $resolution;
    }

    /**
     * After the controller, before StartSession saves: re-put state so it
     * survives regenerate()/invalidate(); apply the consent-gated cookie.
     */
    public function afterController(Request $request, Response $response): void
    {
        $session = $this->session;

        if ($session === null) {
            return;
        }

        FailOpen::run(function () use ($request, $response, $session): void {
            $manager = app(SessionManager::class);
            $manager->persist($request, $session->state);
            $manager->applyCookie($response, $session);
        }, 'request_session');
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->terminated) {
            return;
        }

        if (Recorder::enabled()) {
            FailOpen::run(fn () => $this->finish($request, $response), 'request_terminate');
        }

        $this->terminated = true;

        FailOpen::run(fn () => app(Flusher::class)->flush(), 'flush');

        FailOpen::run(function (): void {
            app(ContextStore::class)->clear();
            app(Buffer::class)->reset();
        }, 'request_reset');

        $this->request = null;
        $this->session = null;
    }

    private function finish(Request $request, Response $response): void
    {
        $store = app(ContextStore::class);
        $route = RouteNamer::forRequest($request);
        $actor = $store->actor() ?? FailOpen::run(fn () => app(ActorResolver::class)->resolve(), 'actor_resolve');
        $tenant = $store->tenant() ?? FailOpen::run(fn () => app(TenantResolver::class)->resolve(), 'tenant_resolve');

        if (! $this->sessionDeferred && RequestClassifier::isPageView($request, $response, $route)) {
            app(Recorder::class)->record((string) config('tendril.http.page_view_event', 'page.viewed'), [
                'status' => $response->getStatusCode(),
            ], [
                'occurred_at' => $this->startedAt,
                'source' => 'http',
                'context' => ['route' => $route, 'navigation' => RequestClassifier::navigation($request)],
                'dedupe_key' => DedupeKey::pageView($store->requestId() ?? (string) Str::ulid()),
            ]);

            // Fills the landing route only if still empty (e.g. the session began on a POST).
            if ($this->session !== null) {
                app(Buffer::class)->session($this->session->state->id, ['landing_route' => $route]);
            }
        }

        if ($this->session !== null) {
            app(Buffer::class)->session($this->session->state->id, array_filter([
                'actor_type' => $actor?->type,
                'actor_id' => $actor?->id,
                'tenant_type' => $tenant?->type,
                'tenant_id' => $tenant?->id,
            ]));
        }

        if ($actor !== null) {
            app(PresenceManager::class)->touch($actor, $tenant);
        }
    }

    private function requestId(Request $request): ?string
    {
        if (! config('tendril.http.trust_request_id_header', false)) {
            return null;
        }

        $value = $request->headers->get((string) config('tendril.http.request_id_header', 'X-Request-Id'));

        return is_string($value) && preg_match('/^[A-Za-z0-9_\-.]{8,64}$/', $value) === 1 ? $value : null;
    }

    /**
     * Adopt an external trace id: W3C traceparent first, then the configured header.
     */
    private function traceId(Request $request): ?string
    {
        $traceparent = $request->headers->get('traceparent');

        if (is_string($traceparent) && preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/', strtolower($traceparent), $m) === 1) {
            return $m[1];
        }

        $value = $request->headers->get((string) config('tendril.http.trace_id_header', 'X-Trace-Id'));

        return is_string($value) && preg_match('/^[A-Za-z0-9_\-.]{8,64}$/', $value) === 1 ? $value : null;
    }
}
