<?php

namespace VitisStudio\Tendril\Core\Contracts;

/**
 * Reliable queue of serialized batches used by the redis-buffer transport.
 * Claimed items move to a processing list and are only removed once
 * acknowledged, so a worker crash between dequeue and persist loses nothing.
 */
interface BufferStore
{
    /** Append a payload; returns the pending length after the push. */
    public function push(string $payload): int;

    public function pending(): int;

    /**
     * Claim up to $max payloads, moving them to the processing list.
     *
     * @return list<string>
     */
    public function claim(int $max): array;

    public function acknowledge(string $payload): void;

    /** Number of claimed but unacknowledged payloads. */
    public function inFlight(): int;

    /** Move payloads claimed longer ago than $seconds back to pending. */
    public function requeueStale(int $seconds): int;
}
