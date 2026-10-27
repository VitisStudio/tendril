<?php

namespace VitisStudio\Tendril\Core\Identity;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Read-side alias ranges: attributes an anonymous event to an actor only when
 * the event falls inside an effective range. Load once per computation.
 */
final class AliasResolver
{
    /** @var array<string, list<array{actor: ActorRef, from: int, to: ?int}>> times in microseconds */
    private array $ranges = [];

    /** @var array<string, true> */
    private array $loaded = [];

    public function __construct(private readonly Database $db) {}

    public static function make(): self
    {
        return new self(app(Database::class));
    }

    /**
     * @param  iterable<string>  $anonymousIds
     */
    public function load(iterable $anonymousIds): void
    {
        $pending = [];

        foreach ($anonymousIds as $id) {
            if ($id !== '' && ! isset($this->loaded[$id])) {
                $pending[$id] = true;
                $this->loaded[$id] = true;
                $this->ranges[$id] ??= [];
            }
        }

        foreach (array_chunk(array_keys($pending), 500) as $chunk) {
            $rows = $this->db->table(Tables::ALIASES)
                ->whereIn('anonymous_id', $chunk)
                ->orderBy('valid_from')
                ->get(['anonymous_id', 'actor_type', 'actor_id', 'valid_from', 'valid_to']);

            foreach ($rows as $row) {
                $this->ranges[(string) $row->anonymous_id][] = [
                    'actor' => new ActorRef((string) $row->actor_type, (string) $row->actor_id),
                    'from' => self::micros(Instant::parse($row->valid_from)),
                    'to' => $row->valid_to !== null ? self::micros(Instant::parse($row->valid_to)) : null,
                ];
            }
        }
    }

    public function resolve(string $anonymousId, CarbonImmutable $at): ?ActorRef
    {
        $this->load([$anonymousId]);
        $t = self::micros($at);
        $match = null;

        foreach ($this->ranges[$anonymousId] ?? [] as $range) {
            if ($range['from'] <= $t && ($range['to'] === null || $t < $range['to'])) {
                $match = $range['actor'];
            }
        }

        return $match;
    }

    /**
     * Whether every instant in [min, max] resolves identically (no range boundary inside).
     */
    public function isDeterminate(string $anonymousId, CarbonImmutable $min, CarbonImmutable $max): bool
    {
        $this->load([$anonymousId]);
        $lo = self::micros($min);
        $hi = self::micros($max);

        foreach ($this->ranges[$anonymousId] ?? [] as $range) {
            if ($range['from'] > $lo && $range['from'] <= $hi) {
                return false;
            }

            if ($range['to'] !== null && $range['to'] > $lo && $range['to'] <= $hi) {
                return false;
            }
        }

        return true;
    }

    public function hasAliases(string $anonymousId): bool
    {
        $this->load([$anonymousId]);

        return ($this->ranges[$anonymousId] ?? []) !== [];
    }

    /**
     * @return list<string>
     */
    public function anonymousIdsFor(ActorRef $actor): array
    {
        return array_values($this->db->table(Tables::ALIASES)
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id)
            ->distinct()
            ->pluck('anonymous_id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }

    /**
     * Subject for derived data: the actor, else the alias-resolved actor, else
     * the anonymous visitor, else null.
     */
    public function subject(?string $actorType, ?string $actorId, ?string $anonymousId, CarbonImmutable $at): ?ActorRef
    {
        if ($actorType !== null && $actorType !== '' && $actorId !== null && $actorId !== '') {
            return new ActorRef($actorType, $actorId);
        }

        if ($anonymousId === null || $anonymousId === '') {
            return null;
        }

        return $this->resolve($anonymousId, $at) ?? ActorRef::anonymous($anonymousId);
    }

    public static function micros(CarbonImmutable $at): int
    {
        return $at->getTimestamp() * 1_000_000 + (int) $at->format('u');
    }
}
