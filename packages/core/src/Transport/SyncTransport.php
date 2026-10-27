<?php

namespace VitisStudio\Tendril\Core\Transport;

use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Contracts\Transport;
use VitisStudio\Tendril\Core\Storage\BatchWriter;

/**
 * Writes directly (after the response). For tests, local and tiny deployments.
 */
final class SyncTransport implements Transport
{
    public function send(Batch $batch): void
    {
        app(BatchWriter::class)->write($batch);
    }
}
