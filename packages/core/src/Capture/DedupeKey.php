<?php

namespace VitisStudio\Tendril\Core\Capture;

use InvalidArgumentException;

/**
 * Deterministic idempotency keys: request + component + action + ordinal, or
 * a client-supplied ULID. Keys longer than the column are hashed so they stay
 * deterministic across retries.
 */
final class DedupeKey
{
    public const int MAX_LENGTH = 191;

    public static function normalize(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException('Dedupe keys cannot be empty.');
        }

        if (strlen($key) <= self::MAX_LENGTH) {
            return $key;
        }

        return 'h:'.substr($key, 0, 40).':'.hash('sha256', $key);
    }

    public static function pageView(string $requestId): string
    {
        return self::normalize("pv:{$requestId}");
    }

    public static function livewire(string $requestId, string $component, string $action, int $ordinal): string
    {
        return self::normalize("lw:{$requestId}:{$component}:{$action}:{$ordinal}");
    }

    public static function auth(string $requestId, string $name, int $ordinal): string
    {
        return self::normalize("auth:{$requestId}:{$name}:{$ordinal}");
    }

    public static function beacon(string $clientId): string
    {
        return self::normalize("bc:{$clientId}");
    }
}
