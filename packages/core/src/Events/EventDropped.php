<?php

namespace VitisStudio\Tendril\Core\Events;

/**
 * An event was intentionally not recorded (tracking decision, sampling, buffer overflow).
 */
final class EventDropped
{
    public function __construct(
        public readonly string $name,
        public readonly string $reason,
    ) {}
}
