<?php

namespace VitisStudio\Tendril\Core\Events;

/**
 * An event failed validation (bad name, oversized payload, clock skew, cardinality).
 */
final class EventRejected
{
    public function __construct(
        public readonly string $name,
        public readonly string $reason,
    ) {}
}
