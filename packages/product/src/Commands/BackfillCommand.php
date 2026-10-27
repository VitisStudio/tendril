<?php

namespace VitisStudio\Tendril\Product\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;
use VitisStudio\Tendril\Product\Aggregation\DefinitionVersions;

/**
 * Chunked, resumable, observable backfill of derived data. Progress is kept
 * in tendril_state, so the command can be interrupted (paused) at any time
 * and resumes from its cursor; marks and recomputation are idempotent.
 */
final class BackfillCommand extends Command
{
    public const string CURSOR = 'backfill.cursor';

    protected $signature = 'tendril:backfill
        {--from= : Start date (required unless resuming)}
        {--to= : End date (defaults to now)}
        {--days=7 : Days per chunk}
        {--restart : Ignore a saved cursor}';

    protected $description = 'Backfill rollups and actor activity from raw events in resumable chunks';

    public function handle(DefinitionVersions $versions, Aggregator $aggregator, StateStore $state): int
    {
        $saved = $this->option('restart') ? null : $state->get(self::CURSOR);
        $cursor = is_array($saved) && isset($saved['next'], $saved['to']) ? Instant::parse($saved['next']) : null;
        $to = is_array($saved) && isset($saved['to']) && ! $this->option('restart') ? Instant::parse($saved['to']) : null;

        if ($cursor === null) {
            if (! is_string($this->option('from'))) {
                $this->error('--from is required (no backfill to resume).');

                return self::FAILURE;
            }

            $cursor = Instant::day(Instant::parse($this->option('from')));
            $to = is_string($this->option('to')) ? Instant::parse($this->option('to'))->endOfDay() : Instant::now();
        } else {
            $this->info("Resuming backfill at {$cursor->toDateString()}.");
        }

        $to ??= Instant::now();
        $step = max(1, (int) $this->option('days'));

        while ($cursor->lessThanOrEqualTo($to)) {
            $chunkEnd = $cursor->addDays($step)->subSecond();
            $chunkEnd = $chunkEnd->greaterThan($to) ? $to : $chunkEnd;

            $marked = $versions->markDaysWithEvents($cursor, $chunkEnd, 'backfill');
            $result = $aggregator->drain();

            $this->line(sprintf(' %s → %s: %d buckets queued, %d hours aggregated', $cursor->toDateString(), $chunkEnd->toDateString(), $marked, $result->hours));

            $cursor = Instant::day($chunkEnd)->addDay();
            $state->put(self::CURSOR, ['next' => Instant::serialize($cursor), 'to' => Instant::serialize($to)]);
        }

        $state->forget(self::CURSOR);
        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
