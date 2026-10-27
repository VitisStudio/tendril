<?php

namespace VitisStudio\Tendril\Timescale\Commands;

use Illuminate\Console\Command;
use Throwable;
use VitisStudio\Tendril\Timescale\CapabilityDetector;
use VitisStudio\Tendril\Timescale\TimescaleMaintenance;

final class InstallCommand extends Command
{
    protected $signature = 'tendril:timescale:install
        {--force : Convert even when the events table is large (rewrites data)}
        {--detect : Only print detected capabilities}';

    protected $description = 'Detect TimescaleDB capabilities and convert tendril_events into a hypertable';

    public function handle(CapabilityDetector $detector, TimescaleMaintenance $maintenance): int
    {
        $capabilities = $detector->detect();
        $this->table(['Capability', 'Value'], collect($capabilities->toArray())->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '—')])->values()->all());

        if ($this->option('detect')) {
            return self::SUCCESS;
        }

        try {
            foreach ($maintenance->install((bool) $this->option('force')) as $line) {
                $this->info($line);
            }

            foreach ($maintenance->prepare(now()->toImmutable()) as $line) {
                $this->line(" - {$line}");
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->comment('Set TENDRIL_STORAGE_ADAPTER=timescale to use drop_chunks for retention.');

        return self::SUCCESS;
    }
}
