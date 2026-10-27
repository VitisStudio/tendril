<?php

namespace VitisStudio\Tendril\Product\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;
use VitisStudio\Tendril\Product\Aggregation\DefinitionVersions;

/**
 * Rollups are disposable: queue every retained bucket in a range for
 * recomputation (e.g. after fixing a calculation bug), optionally run now.
 */
final class RebuildRollupsCommand extends Command
{
    protected $signature = 'tendril:rebuild-rollups
        {--from= : Start date (defaults to the first retained event)}
        {--to= : End date (defaults to now)}
        {--run : Aggregate immediately instead of leaving it to the scheduler}';

    protected $description = 'Queue derived data (rollups + activity) for rebuild from raw events';

    public function handle(DefinitionVersions $versions, Database $db, Aggregator $aggregator): int
    {
        $from = is_string($this->option('from')) ? Instant::parse($this->option('from')) : Instant::tryParse($db->table(Tables::EVENTS)->min('occurred_at'));

        if ($from === null) {
            $this->info('No events to rebuild.');

            return self::SUCCESS;
        }

        $to = is_string($this->option('to')) ? Instant::parse($this->option('to'))->endOfDay() : Instant::now();
        $marked = $versions->markDaysWithEvents($from, $to, 'rebuild');
        $this->info("Queued {$marked} hour buckets between {$from->toDateString()} and {$to->toDateString()}.");

        if ($this->option('run')) {
            $result = $aggregator->drain();
            $this->info("Rebuilt {$result->hours} hours across {$result->days} days.");
        }

        return self::SUCCESS;
    }
}
