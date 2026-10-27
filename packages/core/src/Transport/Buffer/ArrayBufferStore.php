<?php

namespace VitisStudio\Tendril\Core\Transport\Buffer;

use VitisStudio\Tendril\Core\Contracts\BufferStore;

/**
 * In-process implementation with the same reliability semantics as the Redis
 * store. Intended for tests and single-process development.
 */
final class ArrayBufferStore implements BufferStore
{
    /** @var list<string> */
    private array $pending = [];

    /** @var array<string, array{payload: string, at: int}> */
    private array $processing = [];

    /** @var (callable(): int)|null */
    private $clock = null;

    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    public function push(string $payload): int
    {
        $this->pending[] = $payload;

        return count($this->pending);
    }

    public function pending(): int
    {
        return count($this->pending);
    }

    public function claim(int $max): array
    {
        $claimed = [];

        while ($max-- > 0 && $this->pending !== []) {
            $payload = array_shift($this->pending);
            $this->processing[sha1($payload)] = ['payload' => $payload, 'at' => $this->now()];
            $claimed[] = $payload;
        }

        return $claimed;
    }

    public function acknowledge(string $payload): void
    {
        unset($this->processing[sha1($payload)]);
    }

    public function inFlight(): int
    {
        return count($this->processing);
    }

    public function requeueStale(int $seconds): int
    {
        $moved = 0;

        foreach ($this->processing as $hash => $item) {
            if ($this->now() - $item['at'] >= $seconds) {
                unset($this->processing[$hash]);
                $this->pending[] = $item['payload'];
                $moved++;
            }
        }

        return $moved;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
