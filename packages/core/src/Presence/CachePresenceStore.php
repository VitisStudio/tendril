<?php

namespace VitisStudio\Tendril\Core\Presence;

use Illuminate\Contracts\Cache\Repository;
use VitisStudio\Tendril\Core\Contracts\PresenceStore;

/**
 * Presence on any Laravel cache store: one expiring map per scope. Updates are
 * read-modify-write (last writer wins), which is acceptable for presence.
 */
final class CachePresenceStore implements PresenceStore
{
    public const int MAX_MEMBERS = 5000;

    public function __construct(private readonly ?string $store = null) {}

    public function touch(string $scope, string $member, int $timestamp, int $ttl): void
    {
        $cache = $this->cache();
        $key = $this->key($scope);
        $map = $this->map($cache, $key);
        $map[$member] = $timestamp;

        $cutoff = $timestamp - $ttl;
        $map = array_filter($map, static fn (int $seen): bool => $seen >= $cutoff);

        if (count($map) > self::MAX_MEMBERS) {
            arsort($map);
            $map = array_slice($map, 0, self::MAX_MEMBERS, true);
        }

        $cache->put($key, $map, $ttl);
    }

    public function members(string $scope, int $since, int $limit): array
    {
        $map = array_filter($this->map($this->cache(), $this->key($scope)), static fn (int $seen): bool => $seen >= $since);
        arsort($map);

        return array_slice($map, 0, max(0, $limit), true);
    }

    public function count(string $scope, int $since): int
    {
        return count(array_filter($this->map($this->cache(), $this->key($scope)), static fn (int $seen): bool => $seen >= $since));
    }

    public function forget(string $scope, string $member): void
    {
        $cache = $this->cache();
        $key = $this->key($scope);
        $map = $this->map($cache, $key);

        if (isset($map[$member])) {
            unset($map[$member]);
            $cache->put($key, $map, 3600);
        }
    }

    /**
     * @return array<string, int>
     */
    private function map(Repository $cache, string $key): array
    {
        $value = $cache->get($key);

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $member => $seen) {
            if (is_numeric($seen)) {
                $out[(string) $member] = (int) $seen;
            }
        }

        return $out;
    }

    private function key(string $scope): string
    {
        return 'tendril:presence:'.$scope;
    }

    private function cache(): Repository
    {
        return app('cache')->store($this->store);
    }
}
