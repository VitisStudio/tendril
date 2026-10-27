<?php

namespace VitisStudio\Tendril\Product\Aggregation;

use Closure;
use DateTimeInterface;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Storage\Pruner;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Definitions are evaluable historically: when any definition set (features,
 * conversions, …) or the rollup algorithm version changes, every retained
 * hour is marked dirty and derived data is recomputed from raw facts.
 */
final class DefinitionVersions
{
    /** @var array<string, Closure(): string> */
    private array $sources = [];

    public function __construct(
        private readonly StateStore $state,
        private readonly DirtyBuckets $dirty,
        private readonly Database $db,
    ) {}

    /**
     * @param  Closure(): string  $hash
     */
    public function register(string $key, Closure $hash): void
    {
        $this->sources[$key] = $hash;
    }

    /**
     * @return list<string> keys that changed (and triggered a rebuild)
     */
    public function sync(): array
    {
        $changed = [];

        foreach ($this->sources as $key => $hash) {
            $current = $hash();
            $stored = $this->state->get("definitions.{$key}");

            if ($stored !== $current) {
                $changed[] = $key;
                $this->state->put("definitions.{$key}", $current);
            }
        }

        $version = (int) $this->state->get('aggregation.version', 0);

        if ($version !== Aggregator::VERSION) {
            $changed[] = 'aggregation.version';
            $this->state->put('aggregation.version', Aggregator::VERSION);
        }

        if ($changed !== []) {
            $this->markRetainedHistory();
        }

        return $changed;
    }

    /**
     * Re-mark every already-aggregated hour that holds events (hourly event
     * rollups exist exactly for those). Hours never aggregated are still
     * dirty from ingestion, so together this covers all retained history
     * without scanning raw events.
     */
    public function markRetainedHistory(): int
    {
        $pruned = $this->state->instant(Pruner::PRUNED_BEFORE);

        $query = $this->db->table(Tables::ROLLUPS)
            ->where('granularity', 'hour')
            ->where('kind', 'event')
            ->distinct()
            ->orderBy('bucket_start');

        if ($pruned !== null) {
            $query->where('bucket_start', '>=', $this->db->time($pruned));
        }

        $marked = 0;
        $batch = [];

        foreach ($query->pluck('bucket_start') as $bucket) {
            $batch[] = Instant::parse($bucket);

            if (count($batch) >= 500) {
                $marked += $this->dirty->mark($batch, 'definitions');
                $batch = [];
            }
        }

        return $marked + $this->dirty->mark($batch, 'definitions');
    }

    /**
     * Mark every hour of each day in [from, to] that holds at least one event.
     */
    public function markDaysWithEvents(DateTimeInterface $from, DateTimeInterface $to, string $reason = 'definitions'): int
    {
        $day = Instant::day($from);
        $end = Instant::parse($to);
        $marked = 0;

        while ($day->lessThanOrEqualTo($end)) {
            $next = $day->addDay();

            $exists = $this->db->table(Tables::EVENTS)
                ->where('occurred_at', '>=', $this->db->time($day))
                ->where('occurred_at', '<', $this->db->time($next))
                ->exists();

            if ($exists) {
                $marked += $this->dirty->markRange($day, $next, $reason);
            }

            $day = $next;
        }

        return $marked;
    }
}
