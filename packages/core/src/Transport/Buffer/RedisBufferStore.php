<?php

namespace VitisStudio\Tendril\Core\Transport\Buffer;

use Illuminate\Redis\Connections\Connection;
use VitisStudio\Tendril\Core\Contracts\BufferStore;

/**
 * Redis lists: {key}:pending -> LMOVE -> {key}:processing, plus a hash of
 * claim timestamps for stale recovery.
 */
final class RedisBufferStore implements BufferStore
{
    public function __construct(
        private readonly string $connection,
        private readonly string $key,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('tendril.transport.redis_buffer.connection', 'default'),
            (string) config('tendril.transport.redis_buffer.key', 'tendril:buffer'),
        );
    }

    public function push(string $payload): int
    {
        return (int) $this->redis()->rpush($this->pendingKey(), $payload);
    }

    public function pending(): int
    {
        return (int) $this->redis()->llen($this->pendingKey());
    }

    public function claim(int $max): array
    {
        $redis = $this->redis();
        $claimed = [];

        for ($i = 0; $i < $max; $i++) {
            $payload = $redis->lmove($this->pendingKey(), $this->processingKey(), 'LEFT', 'RIGHT');

            if (! is_string($payload) || $payload === '') {
                break;
            }

            $redis->hset($this->claimsKey(), sha1($payload), (string) time());
            $claimed[] = $payload;
        }

        return $claimed;
    }

    public function acknowledge(string $payload): void
    {
        $redis = $this->redis();
        $this->removeOne($redis, $this->processingKey(), $payload);
        $redis->hdel($this->claimsKey(), sha1($payload));
    }

    public function inFlight(): int
    {
        return (int) $this->redis()->llen($this->processingKey());
    }

    public function requeueStale(int $seconds): int
    {
        $redis = $this->redis();
        $items = $redis->lrange($this->processingKey(), 0, -1);
        $moved = 0;

        foreach (is_array($items) ? $items : [] as $payload) {
            if (! is_string($payload)) {
                continue;
            }

            $claimedAt = $redis->hget($this->claimsKey(), sha1($payload));

            if (is_numeric($claimedAt) && (time() - (int) $claimedAt) < $seconds) {
                continue;
            }

            if ($this->removeOne($redis, $this->processingKey(), $payload) > 0) {
                $redis->rpush($this->pendingKey(), $payload);
                $moved++;
            }

            $redis->hdel($this->claimsKey(), sha1($payload));
        }

        return $moved;
    }

    /**
     * Laravel's PhpRedisConnection::lrem() normalizes phpredis's (key, value,
     * count) to Predis's (key, count, value), so one call order serves both.
     */
    private function removeOne(Connection $redis, string $key, string $payload): int
    {
        return (int) $redis->lrem($key, 1, $payload);
    }

    private function redis(): Connection
    {
        return app('redis')->connection($this->connection);
    }

    private function pendingKey(): string
    {
        return $this->key.':pending';
    }

    private function processingKey(): string
    {
        return $this->key.':processing';
    }

    private function claimsKey(): string
    {
        return $this->key.':claims';
    }
}
