<?php

namespace VitisStudio\Tendril\Core\Transport;

use Closure;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Contracts\Transport;

/**
 * Resolves the configured transport. Not cached: resolving is trivial and
 * reading config every time keeps long-lived workers honest.
 */
final class Transports
{
    /** @var array<string, Closure(): Transport> */
    private array $custom = [];

    /**
     * @param  Closure(): Transport  $factory
     */
    public function extend(string $driver, Closure $factory): void
    {
        $this->custom[$driver] = $factory;
    }

    public function driver(?string $name = null): Transport
    {
        $name ??= (string) config('tendril.transport.driver', 'queue');

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])();
        }

        return match ($name) {
            'sync' => new SyncTransport,
            'queue' => new QueueTransport,
            'redis-buffer' => new RedisBufferTransport,
            'null' => new NullTransport,
            default => throw new InvalidArgumentException("Unknown Tendril transport [{$name}]."),
        };
    }
}
