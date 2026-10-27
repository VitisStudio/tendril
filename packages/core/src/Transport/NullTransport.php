<?php

namespace VitisStudio\Tendril\Core\Transport;

use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Contracts\Transport;

/**
 * Explicitly disables analytics persistence.
 */
final class NullTransport implements Transport
{
    public function send(Batch $batch): void {}
}
