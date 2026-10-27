<?php

namespace VitisStudio\Tendril\Core\Auth;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Capture\DedupeKey;
use VitisStudio\Tendril\Core\Capture\Recorder;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Support\FailOpen;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * Maps Laravel auth events to facts. Authenticated is deliberately ignored
 * (it fires on every request for session guards); remember-me re-logins are
 * ignored unless configured; credentials are never recorded.
 */
final class AuthEventSubscriber
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Registered::class => 'onRegistered',
            Logout::class => 'onLogout',
            CurrentDeviceLogout::class => 'onCurrentDeviceLogout',
            OtherDeviceLogout::class => 'onOtherDeviceLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
        ];
    }

    public function onLogin(Login $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        FailOpen::run(function () use ($event): void {
            if ($this->viaRemember($event->guard) && ! config('tendril.auth.track_remember_logins', false)) {
                return;
            }

            $actor = ActorRef::tryFrom($event->user);
            $this->record('auth.login', ['guard' => $event->guard, 'remember' => (bool) $event->remember], $actor);
            $this->link($actor, 'login');
        }, 'auth');
    }

    public function onRegistered(Registered $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        FailOpen::run(function () use ($event): void {
            $actor = ActorRef::tryFrom($event->user);
            $this->record('auth.registered', [], $actor);
            $this->link($actor, 'registered');
        }, 'auth');
    }

    public function onLogout(Logout $event): void
    {
        $this->logout($event->guard, $event->user, 'user');
    }

    public function onCurrentDeviceLogout(CurrentDeviceLogout $event): void
    {
        $this->logout($event->guard, $event->user, 'current_device');
    }

    public function onOtherDeviceLogout(OtherDeviceLogout $event): void
    {
        $this->logout($event->guard, $event->user, 'other_devices');
    }

    public function onFailed(Failed $event): void
    {
        if (! $this->enabled() || ! config('tendril.auth.track_failed', false)) {
            return;
        }

        // $event->credentials is never read.
        FailOpen::run(fn () => $this->record('auth.failed', ['guard' => $event->guard], ActorRef::tryFrom($event->user), false), 'auth');
    }

    public function onLockout(Lockout $event): void
    {
        if (! $this->enabled() || ! config('tendril.auth.track_lockout', false)) {
            return;
        }

        FailOpen::run(fn () => $this->record('auth.lockout', [], null, false), 'auth');
    }

    private function logout(?string $guard, ?Authenticatable $user, string $reason): void
    {
        if (! $this->enabled()) {
            return;
        }

        FailOpen::run(function () use ($guard, $user, $reason): void {
            $actor = ActorRef::tryFrom($user);
            $this->record('auth.logout', ['guard' => $guard, 'reason' => $reason], $actor);

            if ($reason !== 'other_devices' && config('tendril.identity.close_alias_on_logout', true)) {
                $this->close($actor);
            }
        }, 'auth');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $name, array $properties, ?ActorRef $actor, bool $withActor = true): void
    {
        $store = app(ContextStore::class);
        $requestId = $store->ensureRoot();
        $ordinal = app(Buffer::class)->nextOrdinal('auth:'.$name);

        app(Recorder::class)->record($name, $properties, [
            'actor' => $withActor ? ($actor ?? false) : false,
            'source' => 'auth',
            'dedupe_key' => DedupeKey::auth($requestId, $name, $ordinal),
        ]);
    }

    private function link(?ActorRef $actor, string $reason): void
    {
        $store = app(ContextStore::class);
        $anonymousId = $store->anonymousId();

        if ($actor === null || $anonymousId === null) {
            return;
        }

        app(Buffer::class)->alias([
            'action' => 'link',
            'anonymous_id' => $anonymousId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'at' => Instant::serialize(Instant::now()),
            'since' => $store->anonymousSince(),
            'reason' => $reason,
        ]);
    }

    private function close(?ActorRef $actor): void
    {
        $anonymousId = app(ContextStore::class)->anonymousId();

        if ($actor === null || $anonymousId === null) {
            return;
        }

        app(Buffer::class)->alias([
            'action' => 'close',
            'anonymous_id' => $anonymousId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'at' => Instant::serialize(Instant::now()),
            'reason' => 'logout',
        ]);
    }

    private function viaRemember(?string $guard): bool
    {
        $instance = app('auth')->guard($guard);

        return method_exists($instance, 'viaRemember') && $instance->viaRemember() === true;
    }

    private function enabled(): bool
    {
        return Recorder::enabled() && (bool) config('tendril.auth.enabled', true);
    }
}
