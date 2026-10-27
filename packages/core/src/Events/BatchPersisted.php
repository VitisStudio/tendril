<?php

namespace VitisStudio\Tendril\Core\Events;

final class BatchPersisted
{
    public function __construct(
        public readonly string $batchId,
        public readonly int $inserted,
        public readonly int $duplicates,
        public readonly int $failed,
    ) {}
}
