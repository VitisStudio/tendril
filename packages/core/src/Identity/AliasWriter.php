<?php

namespace VitisStudio\Tendril\Core\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Storage\Audit;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Applies alias operations with effective ranges.
 *
 * - An anonymous ID links to exactly one actor per range; two actors are never merged.
 * - The first link covers the anonymous history (from "since" or the first event).
 * - Linking to a different actor closes the open range and opens a new one at that instant.
 * - Re-linking after a closed range starts at the link instant (no guessed history).
 * - Every change is audited and marks affected buckets dirty.
 */
final class AliasWriter
{
    public function __construct(
        private readonly Database $db,
        private readonly Audit $audit,
        private readonly DirtyBuckets $dirty,
    ) {}

    /**
     * @param  array<string, mixed>  $op
     */
    public function apply(array $op): void
    {
        $anonymousId = is_string($op['anonymous_id'] ?? null) ? $op['anonymous_id'] : null;
        $actor = ActorRef::tryFrom(['type' => $op['actor_type'] ?? null, 'id' => $op['actor_id'] ?? null]);
        $at = Instant::tryParse($op['at'] ?? null) ?? Instant::now();

        if ($anonymousId === null || $actor === null || $actor->isAnonymous()) {
            return;
        }

        if (($op['action'] ?? 'link') === 'close') {
            $this->close($anonymousId, $actor, $at);

            return;
        }

        $this->link($anonymousId, $actor, $at, Instant::tryParse($op['since'] ?? null), (string) ($op['reason'] ?? 'link'));
    }

    private function link(string $anonymousId, ActorRef $actor, CarbonImmutable $at, ?CarbonImmutable $since, string $reason): void
    {
        $table = $this->db->table(Tables::ALIASES);

        $open = $this->db->table(Tables::ALIASES)
            ->where('anonymous_id', $anonymousId)
            ->whereNull('valid_to')
            ->orderByDesc('valid_from')
            ->first();

        if ($open !== null && $open->actor_type === $actor->type && (string) $open->actor_id === $actor->id) {
            return;
        }

        if ($open !== null) {
            $this->db->table(Tables::ALIASES)->where('id', $open->id)->update(['valid_to' => $this->db->time($at)]);
        }

        $hasHistory = $open !== null || $this->db->table(Tables::ALIASES)->where('anonymous_id', $anonymousId)->exists();

        if ($hasHistory) {
            $from = $at;
        } else {
            $first = Instant::tryParse($this->db->table(Tables::EVENTS)->where('anonymous_id', $anonymousId)->min('occurred_at'));
            $from = $since ?? $first ?? $at;

            if ($first !== null && $first->lessThan($from)) {
                $from = $first;
            }
        }

        if ($from->greaterThan($at)) {
            $from = $at;
        }

        $table->insert([
            'id' => (string) Str::ulid(),
            'anonymous_id' => $anonymousId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'valid_from' => $this->db->time($from),
            'valid_to' => null,
            'reason' => substr($reason, 0, 32),
            'created_at' => $this->db->time(Instant::now()),
        ]);

        // Keyed fingerprints, not raw ids: the audit proves which link happened
        // without keeping identity around after an erasure.
        $this->audit->record('alias.linked', [
            'anonymous' => Audit::fingerprint('anon|'.$anonymousId),
            'actor' => Audit::fingerprint($actor->key()),
            'valid_from' => Instant::serialize($from),
            'closed_previous' => $open !== null,
            'reason' => $reason,
        ]);

        $this->markAnonymousEvents($anonymousId, $from);
    }

    private function close(string $anonymousId, ActorRef $actor, CarbonImmutable $at): void
    {
        $closed = $this->db->table(Tables::ALIASES)
            ->where('anonymous_id', $anonymousId)
            ->where('actor_type', $actor->type)
            ->where('actor_id', $actor->id)
            ->whereNull('valid_to')
            ->where('valid_from', '<=', $this->db->time($at))
            ->update(['valid_to' => $this->db->time($at)]);

        if ($closed > 0) {
            $this->audit->record('alias.closed', [
                'anonymous' => Audit::fingerprint('anon|'.$anonymousId),
                'actor' => Audit::fingerprint($actor->key()),
                'valid_to' => Instant::serialize($at),
            ]);

            $this->markAnonymousEvents($anonymousId, $at);
        }
    }

    /**
     * Derived data attributes anonymous events through aliases, so every
     * alias change reopens the buckets holding that visitor's events.
     */
    private function markAnonymousEvents(string $anonymousId, CarbonImmutable $from): void
    {
        $instants = $this->db->table(Tables::EVENTS)
            ->where('anonymous_id', $anonymousId)
            ->whereNull('actor_id')
            ->where('occurred_at', '>=', $this->db->time($from->startOfHour()))
            ->limit(50000)
            ->pluck('occurred_at')
            ->map(fn ($value) => Instant::parse($value))
            ->all();

        $this->dirty->mark($instants, 'alias');
    }
}
