<?php

namespace VitisStudio\Tendril\Core\Presence;

use Carbon\CarbonImmutable;
use Throwable;
use VitisStudio\Tendril\Core\Contracts\PresenceStore;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\TenantRef;

/**
 * "Online" means recently active. Refreshes are rate limited per actor and
 * scope, counts are tenant-scoped, and every failure degrades silently.
 */
final class PresenceManager
{
    public function touch(ActorRef $actor, ?TenantRef $tenant = null, ?int $timestamp = null): bool
    {
        if (! config('tendril.presence.enabled', true)) {
            return false;
        }

        try {
            $now = $timestamp ?? time();
            $throttle = max(0, (int) config('tendril.presence.throttle_seconds', 60));
            $member = $actor->key();
            $scope = self::scope($tenant);

            if ($throttle > 0 && $timestamp === null) {
                $cache = app('cache')->store(config('tendril.presence.cache_store') ?: null);

                if (! $cache->add('tendril:presence:throttle:'.sha1($scope.'|'.$member), 1, $throttle)) {
                    return false;
                }
            }

            $ttl = $this->ttl();
            $store = app(PresenceStore::class);
            $store->touch('global', $member, $now, $ttl);

            if ($tenant !== null) {
                $store->touch($scope, $member, $now, $ttl);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function count(?TenantRef $tenant = null): int
    {
        try {
            return app(PresenceStore::class)->count(self::scope($tenant), time() - $this->ttl());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{actor: ActorRef, last_seen: CarbonImmutable}>
     */
    public function members(?TenantRef $tenant = null, int $limit = 100): array
    {
        try {
            $members = app(PresenceStore::class)->members(self::scope($tenant), time() - $this->ttl(), $limit);
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($members as $member => $seen) {
            [$type, $id] = array_pad(explode('|', $member, 2), 2, '');

            $actor = ActorRef::tryFrom(['type' => $type, 'id' => $id]);

            if ($actor !== null) {
                $out[] = ['actor' => $actor, 'last_seen' => CarbonImmutable::createFromTimestamp($seen, 'UTC')];
            }
        }

        return $out;
    }

    public function forget(ActorRef $actor, ?TenantRef $tenant = null): void
    {
        try {
            $store = app(PresenceStore::class);
            $store->forget('global', $actor->key());

            if ($tenant !== null) {
                $store->forget(self::scope($tenant), $actor->key());
            }
        } catch (Throwable) {
            // Presence is ephemeral; it expires on its own.
        }
    }

    public static function scope(?TenantRef $tenant): string
    {
        return $tenant === null ? 'global' : 'tenant:'.sha1($tenant->key());
    }

    private function ttl(): int
    {
        return max(10, (int) config('tendril.presence.ttl_seconds', 300));
    }
}
