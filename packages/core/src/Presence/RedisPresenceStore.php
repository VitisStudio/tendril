<?php

namespace VitisStudio\Tendril\Core\Presence;

use Illuminate\Redis\Connections\Connection;
use VitisStudio\Tendril\Core\Contracts\PresenceStore;

/**
 * Presence on Redis sorted sets (score = last seen timestamp).
 */
final class RedisPresenceStore implements PresenceStore
{
    public function __construct(private readonly string $connection = 'default') {}

    public function touch(string $scope, string $member, int $timestamp, int $ttl): void
    {
        $redis = $this->redis();
        $key = $this->key($scope);
        $redis->zadd($key, $timestamp, $member);
        $redis->zremrangebyscore($key, '-inf', (string) ($timestamp - $ttl - 1));
        $redis->expire($key, $ttl * 2);
    }

    public function members(string $scope, int $since, int $limit): array
    {
        $result = $this->redis()->zrevrangebyscore($this->key($scope), '+inf', (string) $since, [
            'withscores' => true,
            'limit' => ['offset' => 0, 'count' => max(1, $limit)],
        ]);

        $out = [];

        foreach (is_array($result) ? $result : [] as $member => $score) {
            $out[(string) $member] = (int) $score;
        }

        return $out;
    }

    public function count(string $scope, int $since): int
    {
        return (int) $this->redis()->zcount($this->key($scope), (string) $since, '+inf');
    }

    public function forget(string $scope, string $member): void
    {
        $this->redis()->zrem($this->key($scope), $member);
    }

    private function key(string $scope): string
    {
        return 'tendril:presence:'.$scope;
    }

    private function redis(): Connection
    {
        return app('redis')->connection($this->connection);
    }
}
