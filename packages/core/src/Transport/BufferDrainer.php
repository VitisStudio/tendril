<?php

namespace VitisStudio\Tendril\Core\Transport;

use Illuminate\Database\QueryException;
use Throwable;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Contracts\BufferStore;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Storage\DeadLetters;

/**
 * Moves batches from the buffer store into the database.
 */
final class BufferDrainer
{
    /**
     * @return array{persisted: int, dead_lettered: int, requeued: int, stopped: bool}
     */
    public function drain(int $maxBatches = 100): array
    {
        $store = app(BufferStore::class);
        $writer = app(BatchWriter::class);
        $timeout = max(1, (int) config('tendril.transport.redis_buffer.processing_timeout', 300));

        $result = ['persisted' => 0, 'dead_lettered' => 0, 'requeued' => $store->requeueStale($timeout), 'stopped' => false];

        foreach ($store->claim($maxBatches) as $payload) {
            $data = json_decode($payload, true);

            if (! is_array($data)) {
                app(DeadLetters::class)->storeRaw($payload, 'undecodable_buffer_payload');
                $store->acknowledge($payload);
                $result['dead_lettered']++;

                continue;
            }

            try {
                $writer->write(Batch::fromArray($data));
                $store->acknowledge($payload);
                $result['persisted']++;
            } catch (QueryException $e) {
                // Storage unavailable: leave the payload claimed; it is requeued
                // after the processing timeout and retried idempotently.
                $result['stopped'] = true;

                break;
            } catch (Throwable $e) {
                app(DeadLetters::class)->storeRaw($payload, 'drain_failed: '.$e->getMessage());
                $store->acknowledge($payload);
                $result['dead_lettered']++;
            }
        }

        return $result;
    }
}
