<?php

namespace VitisStudio\Tendril\Core\Context;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\TenantRef;

/**
 * Tendril's keys on Laravel's Context repository.
 *
 * Laravel already propagates Context into queued jobs (chains and batches
 * included) and merges visible keys into log context. Tendril contributes the
 * keys, keeps them minimal (IDs only, never models) and clears them at the
 * end of every request, job and Octane request. Identity-adjacent values are
 * stored as hidden keys so they never reach logs.
 */
final class ContextStore
{
    public const string REQUEST_ID = 'tendril.request_id';

    public const string TRACE_ID = 'tendril.trace_id';

    public const string SOURCE = 'tendril.source';

    public const string ROUTE = 'tendril.route';

    public const string STACK = 'tendril.stack';

    public const string SCOPE = 'tendril.scope';

    public const string ACTOR = 'tendril.actor';

    public const string TENANT = 'tendril.tenant';

    public const string SESSION_ID = 'tendril.session_id';

    public const string ANONYMOUS_ID = 'tendril.anonymous_id';

    public const string ANONYMOUS_SINCE = 'tendril.anonymous_since';

    /** Keys stored visibly (subject to tendril.context.log). */
    private const array VISIBLE = [self::REQUEST_ID, self::TRACE_ID, self::SOURCE, self::ROUTE, self::STACK, self::SCOPE];

    /** Keys always stored hidden. */
    private const array HIDDEN = [self::ACTOR, self::TENANT, self::SESSION_ID, self::ANONYMOUS_ID, self::ANONYMOUS_SINCE];

    /**
     * Start a fresh root for a request, command or job.
     */
    public function begin(string $source, ?string $requestId = null, ?string $traceId = null): string
    {
        $this->clear();

        $requestId ??= (string) Str::ulid();

        $this->set(self::REQUEST_ID, $requestId);
        $this->set(self::SOURCE, $source);
        $this->set(self::STACK, [$requestId]);

        if ($traceId !== null) {
            $this->set(self::TRACE_ID, $traceId);
        }

        return $requestId;
    }

    /**
     * Ensure a root exists (synthetic root for commands, tests and scheduled work).
     */
    public function ensureRoot(string $source = 'app'): string
    {
        $existing = $this->requestId();

        if ($existing !== null) {
            return $existing;
        }

        $requestId = (string) Str::ulid();
        $this->set(self::REQUEST_ID, $requestId);
        $this->set(self::STACK, [$requestId]);

        if ($this->get(self::SOURCE) === null) {
            $this->set(self::SOURCE, $source);
        }

        return $requestId;
    }

    public function clear(): void
    {
        Context::forget(self::VISIBLE);
        Context::forgetHidden(self::VISIBLE);
        Context::forgetHidden(self::HIDDEN);
    }

    public function requestId(): ?string
    {
        $value = $this->get(self::REQUEST_ID);

        return is_string($value) ? $value : null;
    }

    public function traceId(): ?string
    {
        $value = $this->get(self::TRACE_ID);

        return is_string($value) ? $value : null;
    }

    public function source(): ?string
    {
        $value = $this->get(self::SOURCE);

        return is_string($value) ? $value : null;
    }

    public function setSource(string $source): void
    {
        $this->set(self::SOURCE, $source);
    }

    public function route(): ?string
    {
        $value = $this->get(self::ROUTE);

        return is_string($value) ? $value : null;
    }

    public function setRoute(?string $route): void
    {
        $route === null ? $this->forget(self::ROUTE) : $this->set(self::ROUTE, $route);
    }

    /**
     * Parent event id: the innermost pushed event, never the root request id.
     */
    public function parentId(): ?string
    {
        $stack = $this->stack();

        return count($stack) > 1 ? $stack[count($stack) - 1] : null;
    }

    /**
     * @return list<string>
     */
    public function stack(): array
    {
        $stack = $this->get(self::STACK);

        return is_array($stack) ? array_values(array_filter($stack, 'is_string')) : [];
    }

    /**
     * Push an event id so events recorded inside the scope get it as parent_id.
     * Returns false when the depth cap is reached (the push is ignored).
     */
    public function pushParent(string $eventId): bool
    {
        $this->ensureRoot();
        $stack = $this->stack();

        if (count($stack) >= max(2, (int) config('tendril.context.max_depth', 16))) {
            return false;
        }

        if ($this->visible()) {
            Context::push(self::STACK, $eventId);
        } else {
            $stack[] = $eventId;
            Context::addHidden(self::STACK, $stack);
        }

        return true;
    }

    public function popParent(string $eventId): void
    {
        $stack = $this->stack();

        if ($stack !== [] && end($stack) === $eventId && count($stack) > 1) {
            array_pop($stack);
            $this->set(self::STACK, $stack);
        }
    }

    /**
     * @return array<string, scalar>
     */
    public function scope(): array
    {
        $scope = $this->get(self::SCOPE);

        return is_array($scope) ? array_filter($scope, 'is_scalar') : [];
    }

    /**
     * @param  array<string, scalar>  $scope
     */
    public function setScope(array $scope): void
    {
        $scope === [] ? $this->forget(self::SCOPE) : $this->set(self::SCOPE, $scope);
    }

    public function actor(): ?ActorRef
    {
        return ActorRef::tryFrom(Context::getHidden(self::ACTOR));
    }

    public function setActor(?ActorRef $actor): void
    {
        $actor === null ? Context::forgetHidden(self::ACTOR) : Context::addHidden(self::ACTOR, $actor->toArray());
    }

    public function tenant(): ?TenantRef
    {
        return TenantRef::tryFrom(Context::getHidden(self::TENANT));
    }

    public function setTenant(?TenantRef $tenant): void
    {
        $tenant === null ? Context::forgetHidden(self::TENANT) : Context::addHidden(self::TENANT, $tenant->toArray());
    }

    public function sessionId(): ?string
    {
        $value = Context::getHidden(self::SESSION_ID);

        return is_string($value) ? $value : null;
    }

    public function anonymousId(): ?string
    {
        $value = Context::getHidden(self::ANONYMOUS_ID);

        return is_string($value) ? $value : null;
    }

    public function anonymousSince(): ?string
    {
        $value = Context::getHidden(self::ANONYMOUS_SINCE);

        return is_string($value) ? $value : null;
    }

    public function setVisitor(?string $sessionId, ?string $anonymousId, ?string $anonymousSince = null): void
    {
        $sessionId === null ? Context::forgetHidden(self::SESSION_ID) : Context::addHidden(self::SESSION_ID, $sessionId);
        $anonymousId === null ? Context::forgetHidden(self::ANONYMOUS_ID) : Context::addHidden(self::ANONYMOUS_ID, $anonymousId);
        $anonymousSince === null ? Context::forgetHidden(self::ANONYMOUS_SINCE) : Context::addHidden(self::ANONYMOUS_SINCE, $anonymousSince);
    }

    /**
     * Snapshot of the Tendril keys (for diagnostics and tests).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $out = [];

        foreach (self::VISIBLE as $key) {
            $out[$key] = $this->get($key);
        }

        foreach (self::HIDDEN as $key) {
            $out[$key] = Context::getHidden($key);
        }

        return array_filter($out, static fn ($v) => $v !== null);
    }

    private function visible(): bool
    {
        return (bool) config('tendril.context.log', true);
    }

    private function set(string $key, mixed $value): void
    {
        if ($this->visible()) {
            Context::add($key, $value);
        } else {
            Context::addHidden($key, $value);
        }
    }

    private function get(string $key): mixed
    {
        return Context::get($key) ?? Context::getHidden($key);
    }

    private function forget(string $key): void
    {
        Context::forget($key);
        Context::forgetHidden($key);
    }
}
