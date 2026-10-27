<?php

namespace VitisStudio\Tendril\Core;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Traits\Macroable;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Capture\Flusher;
use VitisStudio\Tendril\Core\Capture\Recorder;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Contracts\ActorResolver;
use VitisStudio\Tendril\Core\Contracts\ConsentResolver;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;
use VitisStudio\Tendril\Core\Contracts\TrackingDecision;
use VitisStudio\Tendril\Core\Decisions\DecisionPipeline;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\CallbackActorResolver;
use VitisStudio\Tendril\Core\Identity\CallbackConsentResolver;
use VitisStudio\Tendril\Core\Identity\CallbackTenantResolver;
use VitisStudio\Tendril\Core\Identity\TenantRef;
use VitisStudio\Tendril\Core\Presence\PresenceManager;
use VitisStudio\Tendril\Core\Privacy\ErasureManager;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\ObservabilityLinks;
use VitisStudio\Tendril\Core\Testing\RecordingTransport;
use VitisStudio\Tendril\Core\Transport\Transports;

/**
 * Public API behind the Tendril facade. Stateless: every per-request value
 * lives in scoped services or Laravel Context, so the singleton is Octane-safe.
 * Higher packages add macros (feature(), conversion(), …).
 */
class Tendril
{
    use Macroable;

    /**
     * Record an explicit business fact. Returns the event id, or null when the
     * event was rejected/dropped/disabled. Never throws into the host app.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, scalar>  $context
     * @param  mixed  $actor  model/reference, false to force anonymous, null to resolve
     * @param  mixed  $tenant  model/reference, false to force none, null to resolve
     */
    public function track(
        string $name,
        array $properties = [],
        ?DateTimeInterface $occurredAt = null,
        mixed $actor = null,
        mixed $tenant = null,
        ?string $dedupeKey = null,
        array $context = [],
        ?string $source = null,
        int $version = 1,
    ): ?string {
        $options = ['version' => $version];

        if ($occurredAt !== null) {
            $options['occurred_at'] = $occurredAt;
        }

        if ($actor !== null) {
            $options['actor'] = $actor;
        }

        if ($tenant !== null) {
            $options['tenant'] = $tenant;
        }

        if ($dedupeKey !== null) {
            $options['dedupe_key'] = $dedupeKey;
        }

        if ($context !== []) {
            $options['context'] = $context;
        }

        if ($source !== null) {
            $options['source'] = $source;
        }

        return app(Recorder::class)->record($name, $properties, $options);
    }

    /**
     * Run $callback with safe scalar context merged into every event it records
     * (including events from jobs it dispatches, via Laravel Context).
     *
     * @template T
     *
     * @param  array<string, scalar>  $context
     * @param  callable(): T  $callback
     * @return T
     */
    public function withContext(array $context, callable $callback): mixed
    {
        $store = app(ContextStore::class);
        $previous = $store->scope();
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $key) === 1 && is_scalar($value)) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
            }
        }

        $store->setScope(array_merge($previous, $clean));

        try {
            return $callback();
        } finally {
            $store->setScope($previous);
        }
    }

    /**
     * Events recorded inside $callback get $eventId as parent_id.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withParent(string $eventId, callable $callback): mixed
    {
        $store = app(ContextStore::class);
        $pushed = $store->pushParent($eventId);

        try {
            return $callback();
        } finally {
            if ($pushed) {
                $store->popParent($eventId);
            }
        }
    }

    /**
     * Explicitly identify the actor for the rest of this unit of work and link
     * the current anonymous visitor to it (audited alias range).
     */
    public function identify(mixed $actor): void
    {
        $ref = ActorRef::from($actor);
        $store = app(ContextStore::class);
        $store->setActor($ref);

        if (($anonymousId = $store->anonymousId()) !== null) {
            $this->queueAlias($anonymousId, $ref, 'identify', $store->anonymousSince());
        }
    }

    /**
     * Link an anonymous id to an actor. Never merges two actors.
     */
    public function alias(string $anonymousId, mixed $actor): void
    {
        $this->queueAlias($anonymousId, ActorRef::from($actor), 'alias', null);
        app(ContextStore::class)->ensureRoot();
    }

    public function setTenant(mixed $tenant): void
    {
        app(ContextStore::class)->setTenant(TenantRef::tryFrom($tenant));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function forTenant(mixed $tenant, callable $callback): mixed
    {
        $store = app(ContextStore::class);
        $previous = $store->tenant();
        $store->setTenant(TenantRef::tryFrom($tenant));

        try {
            return $callback();
        } finally {
            $store->setTenant($previous);
        }
    }

    /**
     * Hand the current buffer to the transport now.
     */
    public function flush(): void
    {
        app(Flusher::class)->flush();
    }

    public function erase(mixed $actor, ?string $performedBy = null): string
    {
        return app(ErasureManager::class)->eraseActor(ActorRef::from($actor), $performedBy);
    }

    public function eraseAnonymous(string $anonymousId, ?string $performedBy = null): string
    {
        return app(ErasureManager::class)->eraseAnonymous($anonymousId, $performedBy);
    }

    public function presence(): PresenceManager
    {
        return app(PresenceManager::class);
    }

    public function requestId(): ?string
    {
        return app(ContextStore::class)->requestId();
    }

    public function sessionId(): ?string
    {
        return app(ContextStore::class)->sessionId();
    }

    public function anonymousId(): ?string
    {
        return app(ContextStore::class)->anonymousId();
    }

    public function enabled(): bool
    {
        return Recorder::enabled();
    }

    /**
     * Observability pivots for a stored event (see tendril.observability.links).
     *
     * @param  array<string, mixed>|object  $event
     * @return array<string, string>
     */
    public function observabilityLinks(array|object $event): array
    {
        return app(ObservabilityLinks::class)->for($event);
    }

    /**
     * @param  Closure(\Illuminate\Http\Request): bool  $callback
     */
    public function consentUsing(Closure $callback): void
    {
        app()->instance(ConsentResolver::class, new CallbackConsentResolver($callback));
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    public function resolveActorUsing(Closure $callback): void
    {
        app()->instance(ActorResolver::class, new CallbackActorResolver($callback));
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    public function resolveTenantUsing(Closure $callback): void
    {
        app()->instance(TenantResolver::class, new CallbackTenantResolver($callback));
    }

    /**
     * Add a tracking decision at runtime (in addition to tendril.decisions).
     */
    public function decideUsing(TrackingDecision $decision): void
    {
        app(DecisionPipeline::class)->push($decision);
    }

    /**
     * Swap the transport for an in-memory recorder (host application tests).
     */
    public function fake(): RecordingTransport
    {
        $fake = new RecordingTransport;
        app(Transports::class)->extend('recording', fn () => $fake);
        config(['tendril.transport.driver' => 'recording', 'tendril.enabled' => true]);
        app()->instance(RecordingTransport::class, $fake);

        return $fake;
    }

    private function queueAlias(string $anonymousId, ActorRef $actor, string $reason, ?string $since): void
    {
        app(Buffer::class)->alias([
            'action' => 'link',
            'anonymous_id' => $anonymousId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'at' => Instant::serialize(Instant::now()),
            'since' => $since,
            'reason' => $reason,
        ]);
    }
}
