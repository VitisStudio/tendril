<?php

namespace VitisStudio\Tendril\Core\Contracts;

/**
 * Ephemeral "recently active" storage. Separate from durable analytics.
 */
interface PresenceStore
{
    public function touch(string $scope, string $member, int $timestamp, int $ttl): void;

    /**
     * Members seen at or after $since, most recent first.
     *
     * @return array<string, int> member => last seen timestamp
     */
    public function members(string $scope, int $since, int $limit): array;

    public function count(string $scope, int $since): int;

    public function forget(string $scope, string $member): void;
}
