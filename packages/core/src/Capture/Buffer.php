<?php

namespace VitisStudio\Tendril\Core\Capture;

use VitisStudio\Tendril\Core\Support\Instant;

/**
 * Per-unit-of-work in-memory buffer. Registered as a scoped binding so Octane
 * and queue workers get a fresh instance per request/job; Tendril also drains
 * and resets it explicitly at every lifecycle boundary.
 */
final class Buffer
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    /** @var array<string, array<string, mixed>> */
    private array $touchpoints = [];

    /** @var list<array<string, mixed>> */
    private array $aliases = [];

    /** @var array<string, int> */
    private array $ordinals = [];

    private int $dropped = 0;

    /**
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): bool
    {
        if (count($this->events) >= $this->maxEvents()) {
            $this->dropped++;

            return false;
        }

        $this->events[] = $event;

        return true;
    }

    /**
     * Merge a session operation: open data is kept from the first op, last
     * seen moves forward, actor/tenant keep the latest non-null value.
     *
     * @param  array<string, mixed>  $op
     */
    public function session(string $id, array $op): void
    {
        $current = $this->sessions[$id] ?? ['id' => $id];

        if (isset($op['open']) && ! isset($current['open'])) {
            $current['open'] = $op['open'];
        }

        $seen = Instant::tryParse($op['last_seen_at'] ?? null);
        $existing = Instant::tryParse($current['last_seen_at'] ?? null);

        if ($seen !== null && ($existing === null || $seen->greaterThan($existing))) {
            $current['last_seen_at'] = Instant::serialize($seen);
        }

        foreach (['actor_type', 'actor_id', 'tenant_type', 'tenant_id'] as $key) {
            if (isset($op[$key])) {
                $current[$key] = $op[$key];
            }
        }

        if (isset($op['landing_route']) && ! isset($current['landing_route'])) {
            $current['landing_route'] = $op['landing_route'];
        }

        $this->sessions[$id] = $current;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function touchpoint(array $row): void
    {
        $sessionId = (string) ($row['session_id'] ?? '');

        if ($sessionId !== '' && ! isset($this->touchpoints[$sessionId])) {
            $this->touchpoints[$sessionId] = $row;
        }
    }

    /**
     * @param  array<string, mixed>  $op
     */
    public function alias(array $op): void
    {
        $this->aliases[] = $op;
    }

    public function nextOrdinal(string $key): int
    {
        $this->ordinals[$key] = ($this->ordinals[$key] ?? 0) + 1;

        return $this->ordinals[$key];
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    public function shouldFlush(): bool
    {
        return count($this->events) >= max(1, (int) config('tendril.buffer.flush_threshold', 250));
    }

    public function isEmpty(): bool
    {
        return $this->events === [] && $this->sessions === [] && $this->touchpoints === [] && $this->aliases === [];
    }

    /**
     * Hand everything over as a batch and reset (ordinals survive until reset()
     * so dedupe keys stay unique across early flushes within one request).
     */
    public function drain(): ?Batch
    {
        if ($this->isEmpty()) {
            return null;
        }

        $batch = Batch::make(
            $this->events,
            array_values($this->sessions),
            array_values($this->touchpoints),
            $this->aliases,
        );

        $this->events = [];
        $this->sessions = [];
        $this->touchpoints = [];
        $this->aliases = [];

        return $batch;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->sessions = [];
        $this->touchpoints = [];
        $this->aliases = [];
        $this->ordinals = [];
        $this->dropped = 0;
    }

    private function maxEvents(): int
    {
        return max(1, (int) config('tendril.buffer.max_events', 1000));
    }
}
