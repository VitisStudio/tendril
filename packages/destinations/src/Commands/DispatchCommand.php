<?php

namespace VitisStudio\Tendril\Destinations\Commands;

use Illuminate\Console\Command;
use VitisStudio\Tendril\Destinations\DeliveryDispatcher;

final class DispatchCommand extends Command
{
    protected $signature = 'tendril:destinations:dispatch {--destination= : Only this destination}';

    protected $description = 'Deliver settled conversions to configured destinations (idempotent, retried)';

    public function handle(DeliveryDispatcher $dispatcher): int
    {
        $only = $this->option('destination');
        $summary = $dispatcher->dispatch(is_string($only) && $only !== '' ? $only : null);

        if ($summary === []) {
            $this->info('No destinations configured.');

            return self::SUCCESS;
        }

        $this->table(['Destination', 'Delivered', 'Failed', 'Skipped'], array_map(
            fn (string $name, array $c) => [$name, $c['delivered'], $c['failed'], $c['skipped']],
            array_keys($summary),
            $summary,
        ));

        return self::SUCCESS;
    }
}
