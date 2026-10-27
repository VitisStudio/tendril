<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Core\Support\Instant;

final class PartitionsCommand extends Command
{
    protected $signature = 'tendril:partitions
        {--months= : Months ahead to prepare (defaults to tendril.storage.partitions_ahead_months)}
        {--describe : Only describe current storage}';

    protected $description = 'Prepare native partitions (or hypertable chunks) ahead of time and describe storage';

    public function handle(MaintenanceManager $manager): int
    {
        $adapter = $manager->adapter();
        $this->info('Adapter: '.$adapter->name());

        if (! $this->option('describe')) {
            $months = is_numeric($this->option('months')) ? (int) $this->option('months') : (int) config('tendril.storage.partitions_ahead_months', 3);

            foreach ($adapter->prepare(Instant::now()->startOfMonth()->addMonths(max(0, $months))) as $action) {
                $this->line(" - {$action}");
            }
        }

        $this->line((string) json_encode(['capabilities' => $adapter->capabilities(), 'storage' => $adapter->describe()], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
