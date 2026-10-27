<?php

namespace VitisStudio\Tendril\Core\Contracts;

use VitisStudio\Tendril\Core\Capture\Batch;

/**
 * Hands a unit of work's batch to storage. Implementations may throw; the
 * flusher fails open around them.
 */
interface Transport
{
    public function send(Batch $batch): void;
}
