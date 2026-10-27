<?php

namespace VitisStudio\Tendril\Core\Events;

final class ErasureCompleted
{
    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly string $erasureId,
        public readonly array $counts,
    ) {}
}
