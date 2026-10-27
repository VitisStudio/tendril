<?php

namespace VitisStudio\Tendril\Core\Storage;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Retention: hot raw -> (optional) archive -> prune. Resumable: archive
 * progress and the pruned horizon live in tendril_state, and the pruned
 * horizon also stops aggregation from ever recomputing pruned buckets
 * (which would wipe derived data that can no longer be rebuilt).
 */
final class Pruner
{
    public const string ARCHIVED_THROUGH = 'retention.archived_through';

    public const string PRUNED_BEFORE = 'retention.raw_pruned_before';

    public function __construct(
        private readonly Database $db,
        private readonly StateStore $state,
        private readonly Archiver $archiver,
        private readonly DirtyBuckets $dirty,
    ) {}

    public function cutoff(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $days = config('tendril.retention.raw_days');

        if ($days === null || $days === '' || (int) $days <= 0) {
            return null;
        }

        return Instant::day($now ?? Instant::now())->subDays((int) $days);
    }

    /**
     * @param  (callable(string): void)|null  $report
     * @return array{archived: int, pruned: int, sessions: int, touchpoints: int, dead_letters: int, cutoff: ?string}
     */
    public function run(?CarbonImmutable $now = null, ?callable $report = null, bool $dryRun = false): array
    {
        $report ??= static function (string $line): void {};
        $result = ['archived' => 0, 'pruned' => 0, 'sessions' => 0, 'touchpoints' => 0, 'dead_letters' => 0, 'cutoff' => null];
        $cutoff = $this->cutoff($now);

        if ($cutoff === null) {
            $report('Raw retention is unlimited; nothing to prune.');

            return $result;
        }

        $result['cutoff'] = Instant::serialize($cutoff);

        if ($dryRun) {
            $result['pruned'] = $this->db->table(Tables::EVENTS)->where('occurred_at', '<', $this->db->time($cutoff))->count();
            $report("Dry run: {$result['pruned']} events older than {$cutoff->toDateString()} would be removed.");

            return $result;
        }

        if (config('tendril.retention.archive.enabled', false)) {
            $result['archived'] = $this->archive($cutoff, $report);
        }

        // Record the horizon before deleting: aggregation must never rebuild these buckets.
        $this->state->advance(self::PRUNED_BEFORE, $cutoff);
        $this->dirty->discardBefore($cutoff);

        $chunk = max(100, (int) config('tendril.retention.prune_chunk', 5000));
        $adapter = app(MaintenanceManager::class)->adapter();
        $result['pruned'] = $adapter->pruneBefore($cutoff, $chunk, fn (int $n) => $report("… {$n} events removed"));

        $sessionDays = (int) config('tendril.retention.sessions_days', 395);

        if ($sessionDays > 0) {
            $sessionCutoff = $this->db->time(Instant::day($now ?? Instant::now())->subDays($sessionDays));
            $result['sessions'] = $this->db->table(Tables::SESSIONS)->where('started_at', '<', $sessionCutoff)->delete();
            $result['touchpoints'] = $this->db->table(Tables::TOUCHPOINTS)->where('occurred_at', '<', $sessionCutoff)->delete();
        }

        $deadDays = (int) config('tendril.retention.dead_letters_days', 30);

        if ($deadDays > 0) {
            $result['dead_letters'] = app(DeadLetters::class)->purgeBefore(Instant::now()->subDays($deadDays));
        }

        $report("Pruned {$result['pruned']} events older than {$cutoff->toDateString()}.");

        return $result;
    }

    /**
     * @param  callable(string): void  $report
     */
    private function archive(CarbonImmutable $cutoff, callable $report): int
    {
        $through = $this->state->instant(self::ARCHIVED_THROUGH);
        $first = Instant::tryParse($this->db->table(Tables::EVENTS)->min('occurred_at'));

        if ($first === null) {
            return 0;
        }

        $day = $through !== null ? $through->addDay() : Instant::day($first);
        $total = 0;

        while ($day->lessThan($cutoff)) {
            $count = $this->archiver->archiveDay($day);
            $total += $count;
            $this->state->putInstant(self::ARCHIVED_THROUGH, $day);

            if ($count > 0) {
                $report("Archived {$count} events for {$day->toDateString()}.");
            }

            $day = $day->addDay();
        }

        return $total;
    }
}
