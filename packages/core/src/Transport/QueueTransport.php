<?php

namespace VitisStudio\Tendril\Core\Transport;

use Illuminate\Contracts\Bus\Dispatcher;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Contracts\Transport;
use VitisStudio\Tendril\Core\Transport\Jobs\PersistBatch;

/**
 * One queued job per request/job carrying all of its events.
 */
final class QueueTransport implements Transport
{
    public function send(Batch $batch): void
    {
        $job = new PersistBatch($batch->toArray());

        $connection = config('tendril.transport.queue.connection');
        $queue = config('tendril.transport.queue.queue');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        // Dispatch immediately (not via PendingDispatch destructor) so a queue
        // outage surfaces here, inside the fail-open flusher.
        app(Dispatcher::class)->dispatch($job);
    }
}
