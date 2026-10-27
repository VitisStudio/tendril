<?php

namespace VitisStudio\Tendril\Core\Capture;

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Contracts\ActorResolver;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;
use VitisStudio\Tendril\Core\Decisions\AnalyticsContext;
use VitisStudio\Tendril\Core\Decisions\DecisionPipeline;
use VitisStudio\Tendril\Core\Events\EventDropped;
use VitisStudio\Tendril\Core\Events\EventRejected;
use VitisStudio\Tendril\Core\Http\RequestLifecycle;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\TenantRef;
use VitisStudio\Tendril\Core\Privacy\IpAnonymizer;
use VitisStudio\Tendril\Core\Storage\IngestionStats;
use VitisStudio\Tendril\Core\Support\FailOpen;

/**
 * Builds, validates, decides and buffers one event. Never throws (unless
 * tendril.debug.throw is on) and never touches the database or queue.
 */
final class Recorder
{
    /**
     * Supported options: occurred_at, actor (model|ref|false), tenant (model|ref|false),
     * anonymous_id, session_id, source, dedupe_key, version, context, parent_id.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $options
     */
    public function record(string $name, array $properties = [], array $options = []): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        return FailOpen::run(fn (): ?string => $this->capture($name, $properties, $options), 'capture', null, 1);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $options
     */
    private function capture(string $name, array $properties, array $options): ?string
    {
        $store = app(ContextStore::class);
        $requestId = $store->ensureRoot(app()->runningInConsole() ? 'console' : 'app');

        $actor = $this->actor($options, $store);
        $tenant = $this->tenant($options, $store);
        $id = (string) Str::ulid();
        $lifecycle = app(RequestLifecycle::class);
        $request = $lifecycle->request();

        $context = [];

        foreach ($store->scope() as $key => $value) {
            if (! in_array($key, EventNormalizer::RESERVED_CONTEXT, true)) {
                $context[$key] = $value;
            }
        }

        if (($route = $store->route()) !== null) {
            $context['route'] = $route;
        }

        if (is_array($options['context'] ?? null)) {
            $context = array_merge($context, $options['context']);
        }

        $ip = IpAnonymizer::apply($request?->ip(), (string) config('tendril.privacy.ip', 'none'));

        if ($ip !== null) {
            $context['ip'] = $ip;
        }

        $event = [
            'id' => $id,
            'occurred_at' => $options['occurred_at'] ?? null,
            'name' => $name,
            'version' => $options['version'] ?? 1,
            'actor_type' => $actor?->type,
            'actor_id' => $actor?->id,
            'anonymous_id' => array_key_exists('anonymous_id', $options) ? $options['anonymous_id'] : $store->anonymousId(),
            'session_id' => array_key_exists('session_id', $options) ? $options['session_id'] : $store->sessionId(),
            'tenant_type' => $tenant?->type,
            'tenant_id' => $tenant?->id,
            'request_id' => $requestId,
            'trace_id' => $store->traceId(),
            'parent_id' => $options['parent_id'] ?? $store->parentId(),
            'dedupe_key' => $options['dedupe_key'] ?? $id,
            'source' => $options['source'] ?? $store->source() ?? 'app',
            'environment' => self::environment(),
            'properties' => $properties,
            'context' => $context,
        ];

        $result = app(EventNormalizer::class)->normalize($event);

        if ($result->rejected() || $result->event === null) {
            app(IngestionStats::class)->increment('rejected');
            FailOpen::dispatch(new EventRejected($name, (string) $result->rejection));

            return null;
        }

        $normalized = $result->event;

        $outcome = app(DecisionPipeline::class)->evaluate(new AnalyticsContext(
            name: (string) $normalized['name'],
            source: (string) $normalized['source'],
            environment: (string) $normalized['environment'],
            properties: (array) $normalized['properties'],
            context: (array) $normalized['context'],
            actor: $actor,
            tenant: $tenant,
            anonymousId: is_string($normalized['anonymous_id'] ?? null) ? $normalized['anonymous_id'] : null,
            request: $request,
        ));

        if (! $outcome->keep) {
            app(IngestionStats::class)->increment('dropped');
            FailOpen::dispatch(new EventDropped((string) $normalized['name'], (string) $outcome->reason));

            return null;
        }

        $buffer = app(Buffer::class);

        if (! $buffer->push($outcome->apply($normalized))) {
            FailOpen::dispatch(new EventDropped((string) $normalized['name'], 'buffer_overflow'));

            return null;
        }

        if ($buffer->shouldFlush()) {
            app(Flusher::class)->flush();
        }

        return $id;
    }

    public static function environment(): string
    {
        $configured = config('tendril.environment');

        return is_string($configured) && $configured !== '' ? $configured : (string) app()->environment();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function actor(array $options, ContextStore $store): ?ActorRef
    {
        if (array_key_exists('actor', $options)) {
            return $options['actor'] === false || $options['actor'] === null ? null : ActorRef::from($options['actor']);
        }

        // A broken host resolver costs the identity, not the fact.
        return $store->actor() ?? FailOpen::run(fn () => app(ActorResolver::class)->resolve(), 'actor_resolve');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function tenant(array $options, ContextStore $store): ?TenantRef
    {
        if (array_key_exists('tenant', $options)) {
            return $options['tenant'] === false || $options['tenant'] === null ? null : TenantRef::from($options['tenant']);
        }

        return $store->tenant() ?? FailOpen::run(fn () => app(TenantResolver::class)->resolve(), 'tenant_resolve');
    }

    public static function enabled(): bool
    {
        return (bool) config('tendril.enabled', true) && config('tendril.transport.driver') !== 'null';
    }
}
