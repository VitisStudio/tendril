<?php

namespace VitisStudio\Tendril\Core\Capture;

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Support\Instant;

/**
 * Everything one unit of work (request, job, command) produced: events plus
 * session, touchpoint and alias operations. Plain arrays only, so it
 * serializes safely into a single queue job or Redis entry.
 */
final class Batch
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $sessions
     * @param  list<array<string, mixed>>  $touchpoints
     * @param  list<array<string, mixed>>  $aliases
     */
    public function __construct(
        public readonly string $id,
        public readonly string $createdAt,
        public readonly array $events = [],
        public readonly array $sessions = [],
        public readonly array $touchpoints = [],
        public readonly array $aliases = [],
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array<string, mixed>>  $sessions
     * @param  list<array<string, mixed>>  $touchpoints
     * @param  list<array<string, mixed>>  $aliases
     */
    public static function make(array $events = [], array $sessions = [], array $touchpoints = [], array $aliases = []): self
    {
        return new self((string) Str::ulid(), Instant::serialize(Instant::now()), $events, $sessions, $touchpoints, $aliases);
    }

    public function isEmpty(): bool
    {
        return $this->events === [] && $this->sessions === [] && $this->touchpoints === [] && $this->aliases === [];
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function withEvents(array $events): self
    {
        return new self($this->id, $this->createdAt, $events, $this->sessions, $this->touchpoints, $this->aliases);
    }

    /**
     * @return array{id: string, created_at: string, events: list<array<string, mixed>>, sessions: list<array<string, mixed>>, touchpoints: list<array<string, mixed>>, aliases: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'events' => $this->events,
            'sessions' => $this->sessions,
            'touchpoints' => $this->touchpoints,
            'aliases' => $this->aliases,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $list = static fn (mixed $value): array => is_array($value) ? array_values(array_filter($value, 'is_array')) : [];

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : (string) Str::ulid(),
            is_string($data['created_at'] ?? null) ? $data['created_at'] : Instant::serialize(Instant::now()),
            $list($data['events'] ?? []),
            $list($data['sessions'] ?? []),
            $list($data['touchpoints'] ?? []),
            $list($data['aliases'] ?? []),
        );
    }
}
