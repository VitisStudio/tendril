<?php

namespace VitisStudio\Tendril\Core\Transport;

use RuntimeException;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Contracts\BufferStore;
use VitisStudio\Tendril\Core\Contracts\Transport;
use VitisStudio\Tendril\Core\Storage\IngestionStats;

/**
 * High-volume transport: compact batches are appended to a Redis list and
 * drained into batch inserts by tendril:drain. Applies backpressure by
 * refusing new batches beyond tendril.transport.redis_buffer.max_pending.
 */
final class RedisBufferTransport implements Transport
{
    public function send(Batch $batch): void
    {
        $store = app(BufferStore::class);
        $max = (int) config('tendril.transport.redis_buffer.max_pending', 50000);

        if ($max > 0 && $store->pending() >= $max) {
            app(IngestionStats::class)->increment('dropped', max(1, $batch->eventCount()));

            throw new RuntimeException('Tendril redis buffer is full (backpressure).');
        }

        $store->push(json_encode($batch->toArray(), JSON_THROW_ON_ERROR));
    }
}
