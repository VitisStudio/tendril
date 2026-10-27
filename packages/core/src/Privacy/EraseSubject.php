<?php

namespace VitisStudio\Tendril\Core\Privacy;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Expression;
use Illuminate\Queue\InteractsWithQueue;
use VitisStudio\Tendril\Core\Contracts\SubjectEraser;
use VitisStudio\Tendril\Core\Events\ErasureCompleted;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Presence\PresenceManager;
use VitisStudio\Tendril\Core\Storage\Audit;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\FailOpen;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\JsonSql;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * The only write path that touches existing events.
 *
 * Chunked and resumable: each run processes a bounded number of chunks and
 * re-dispatches itself; progress is implicit because rewritten rows no
 * longer match. Rewrites actor_id / anonymous_id to per-erasure pseudonyms
 * (distinct counts stay correct, identity is unlinkable), strips configured
 * PII property paths, deletes aliases/sessions/touchpoints, runs registered
 * erasers for derived data and marks affected buckets for rebuild.
 */
final class EraseSubject implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $erasureId,
        public ?string $actorType,
        public ?string $actorId,
        public ?string $anonymousId,
        public int $maxChunks = 50,
    ) {}

    public function handle(Database $db, DirtyBuckets $dirty, Audit $audit): void
    {
        $erasure = $db->table(Tables::ERASURES)->where('id', $this->erasureId)->first();

        if ($erasure === null || $erasure->status === 'completed') {
            return;
        }

        $db->table(Tables::ERASURES)->where('id', $this->erasureId)->update(['status' => 'running']);

        $counts = json_decode((string) $erasure->counts, true);
        $counts = is_array($counts) ? $counts : [];
        $counts += ['events_actor' => 0, 'events_anonymous' => 0, 'aliases' => 0, 'sessions' => 0, 'touchpoints' => 0, 'derived' => 0];

        $actor = ActorRef::tryFrom(['type' => $this->actorType, 'id' => $this->actorId]);
        $anonymousIds = $this->anonymousIds($db, $actor);
        $chunk = max(1, (int) config('tendril.privacy.erasure_chunk', 1000));
        $strip = $this->stripPaths();
        $json = $db->json();
        $budget = $this->maxChunks;

        if ($actor !== null) {
            $budget = $this->rewrite($db, $dirty, $json, $chunk, $budget, $strip, $counts['events_actor'],
                fn ($q) => $q->where('actor_type', $actor->type)->where('actor_id', $actor->id),
                ['actor_id' => (string) $erasure->actor_pseudonym],
            );
        }

        foreach (array_chunk($anonymousIds, 500) as $ids) {
            if ($budget <= 0) {
                break;
            }

            $budget = $this->rewrite($db, $dirty, $json, $chunk, $budget, $strip, $counts['events_anonymous'],
                fn ($q) => $q->whereIn('anonymous_id', $ids),
                ['anonymous_id' => (string) $erasure->anonymous_pseudonym],
            );
        }

        if ($budget <= 0) {
            $this->saveCounts($db, $counts);
            app(Dispatcher::class)->dispatch(new self($this->erasureId, $this->actorType, $this->actorId, $this->anonymousId, $this->maxChunks));

            return;
        }

        $counts['aliases'] += $this->deleteIdentityRows($db, Tables::ALIASES, $actor, $anonymousIds);
        $counts['sessions'] += $this->deleteIdentityRows($db, Tables::SESSIONS, $actor, $anonymousIds);
        $counts['touchpoints'] += $this->deleteIdentityRows($db, Tables::TOUCHPOINTS, $actor, $anonymousIds);

        foreach (app()->tagged('tendril.erasers') as $eraser) {
            if ($eraser instanceof SubjectEraser) {
                $counts['derived'] += $eraser->erase($actor, $anonymousIds);
            }
        }

        if ($actor !== null) {
            app(PresenceManager::class)->forget($actor);
        }

        $db->table(Tables::ERASURES)->where('id', $this->erasureId)->update([
            'status' => 'completed',
            'counts' => json_encode($counts) ?: '[]',
            'completed_at' => $db->time(Instant::now()),
        ]);

        $audit->record('erasure.completed', ['erasure_id' => $this->erasureId, 'counts' => $counts]);

        FailOpen::dispatch(new ErasureCompleted($this->erasureId, $counts));
    }

    /**
     * @param  list<list<string>>  $strip
     * @param  callable(\Illuminate\Database\Query\Builder): mixed  $constraint
     * @param  array<string, string>  $set
     */
    private function rewrite(Database $db, DirtyBuckets $dirty, JsonSql $json, int $chunk, int $budget, array $strip, int &$counter, callable $constraint, array $set): int
    {
        while ($budget > 0) {
            $query = $db->table(Tables::EVENTS)->select(['id', 'occurred_at'])->orderBy('id')->limit($chunk);
            $constraint($query);
            $rows = $query->get();

            if ($rows->isEmpty()) {
                return $budget;
            }

            $ids = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();
            $values = $set;

            if ($strip !== []) {
                $values['properties'] = new Expression($json->remove('properties', $strip));
            }

            $db->connection()->transaction(function () use ($db, $ids, $values): void {
                $db->table(Tables::EVENTS)->whereIn('id', $ids)->update($values);
            });

            $dirty->mark($rows->map(fn ($row) => Instant::parse($row->occurred_at))->all(), 'erasure');
            $counter += count($ids);
            $budget--;
        }

        return $budget;
    }

    /**
     * @return list<string>
     */
    private function anonymousIds(Database $db, ?ActorRef $actor): array
    {
        $ids = $this->anonymousId !== null && $this->anonymousId !== '' ? [$this->anonymousId] : [];

        if ($actor !== null) {
            $aliased = $db->table(Tables::ALIASES)
                ->where('actor_type', $actor->type)
                ->where('actor_id', $actor->id)
                ->distinct()
                ->pluck('anonymous_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $ids = [...$ids, ...$aliased];
        }

        return array_values(array_unique(array_filter($ids, fn ($id) => $id !== '' && ! str_starts_with($id, 'erased:'))));
    }

    /**
     * @param  list<string>  $anonymousIds
     */
    private function deleteIdentityRows(Database $db, string $table, ?ActorRef $actor, array $anonymousIds): int
    {
        $deleted = 0;

        if ($actor !== null) {
            $deleted += $db->table($table)->where('actor_type', $actor->type)->where('actor_id', $actor->id)->delete();
        }

        foreach (array_chunk($anonymousIds, 500) as $ids) {
            $deleted += $db->table($table)->whereIn('anonymous_id', $ids)->delete();
        }

        return $deleted;
    }

    /**
     * @return list<list<string>>
     */
    private function stripPaths(): array
    {
        $paths = [];

        foreach ((array) config('tendril.privacy.erasure_strip_paths', []) as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = JsonSql::splitPath($path);
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function saveCounts(Database $db, array $counts): void
    {
        $db->table(Tables::ERASURES)->where('id', $this->erasureId)->update(['counts' => json_encode($counts) ?: '[]']);
    }
}
