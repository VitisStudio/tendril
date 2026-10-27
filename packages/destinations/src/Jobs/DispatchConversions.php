<?php

namespace VitisStudio\Tendril\Destinations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use VitisStudio\Tendril\Destinations\DeliveryDispatcher;

/**
 * Queue-friendly wrapper; unique so overlapping runs never double-send.
 */
final class DispatchConversions implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 600;

    public function __construct(public ?string $destination = null) {}

    public function uniqueId(): string
    {
        return 'tendril-destinations:'.($this->destination ?? 'all');
    }

    public function handle(DeliveryDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->destination);
    }
}
