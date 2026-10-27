<?php

namespace VitisStudio\Tendril\Core\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Core\Storage\Pruner;

final class PruneCommand extends Command
{
    protected $signature = 'tendril:prune
        {--dry-run : Only report what would be removed}';

    protected $description = 'Apply raw-event retention: archive (optional), then prune in chunks (resumable)';

    public function handle(Pruner $pruner): int
    {
        $result = $pruner->run(report: fn (string $line) => $this->line($line), dryRun: (bool) $this->option('dry-run'));

        $this->table(['Metric', 'Value'], collect($result)->map(fn ($v, $k) => [$k, (string) ($v ?? '—')])->values()->all());

        return self::SUCCESS;
    }
}
