<?php

namespace VitisStudio\Tendril\Core\Events;

use Throwable;

/**
 * Dispatched whenever capture, hand-off or persistence fails. The host app is
 * never interrupted; route this event to your alerting of choice.
 */
final class IngestionFailed
{
    public function __construct(
        public readonly string $stage,
        public readonly string $message,
        public readonly int $events = 0,
        public readonly ?Throwable $exception = null,
    ) {}
}
