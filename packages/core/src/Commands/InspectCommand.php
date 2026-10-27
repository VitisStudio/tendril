<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Diagnostics\EventInspector;

/**
 * Event catalog entry for one event name: volume, versions, sources and an
 * inferred property schema from a bounded sample of recent events.
 */
final class InspectCommand extends Command
{
    protected $signature = 'tendril:inspect
        {event : Event name}
        {--days=7 : Look-back window}
        {--sample=500 : Recent events sampled for the property schema}';

    protected $description = 'Inspect an event: volume, versions, sources and property schema';

    public function handle(EventInspector $inspector): int
    {
        $days = max(1, (int) $this->option('days'));
        $report = $inspector->inspect((string) $this->argument('event'), $days, max(1, (int) $this->option('sample')));

        if ($report['count'] === 0) {
            $this->warn("No [{$report['name']}] events in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->info("{$report['name']}: {$report['count']} events in the last {$days} days");
        $this->table(['Version', 'Events'], array_map(fn ($v, $c) => [$v, $c], array_keys($report['versions']), $report['versions']));
        $this->table(['Source', 'Events'], array_map(fn ($s, $c) => [$s, $c], array_keys($report['sources']), $report['sources']));
        $this->table(['Property', 'Types', 'Present in sample', 'Distinct values (sample)'], array_map(
            fn (string $key, array $info) => [$key, implode('|', $info['types']), $info['present'], $info['distinct']],
            array_keys($report['schema']),
            $report['schema'],
        ));

        return self::SUCCESS;
    }
}
