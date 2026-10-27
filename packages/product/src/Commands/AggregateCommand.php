<?php

namespace VitisStudio\Tendril\Product\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;

final class AggregateCommand extends Command
{
    protected $signature = 'tendril:aggregate
        {--limit= : Hour buckets per run}
        {--loop : Keep running until no dirty buckets remain}';

    protected $description = 'Recompute rollups and actor activity for dirty (new or late) buckets';

    public function handle(Aggregator $aggregator): int
    {
        $limit = is_numeric($this->option('limit')) ? (int) $this->option('limit') : null;
        $progress = fn (CarbonImmutable $day, int $hours) => $this->output->isVerbose()
            ? $this->line(" - {$day->toDateString()}: {$hours} hours")
            : null;

        $result = $this->option('loop')
            ? $aggregator->drain(progress: $progress)
            : $aggregator->run($limit, $progress);

        if ($result->locked) {
            $this->warn('Another aggregation run holds the lock; nothing was recomputed.');

            return self::SUCCESS;
        }

        if ($result->definitionsChanged !== []) {
            $this->warn('Definitions changed ('.implode(', ', $result->definitionsChanged).'); retained history was queued for recomputation.');
        }

        $this->info("Aggregated {$result->hours} hours across {$result->days} days ({$result->skipped} pruned buckets skipped) in {$result->seconds}s.");

        return self::SUCCESS;
    }
}
