<?php

namespace VitisStudio\Tendril\Core\Capture;

use Throwable;
use VitisStudio\Tendril\Core\Storage\IngestionStats;
use VitisStudio\Tendril\Core\Support\FailOpen;
use VitisStudio\Tendril\Core\Transport\Transports;

/**
 * Drains the buffer into the transport. Called after the response, at the end
 * of every job/command, on Octane request termination and on early flush.
 */
final class Flusher
{
    public function flush(): void
    {
        $buffer = app(Buffer::class);
        $stats = app(IngestionStats::class);

        if (($dropped = $buffer->dropped()) > 0) {
            $stats->increment('dropped', $dropped);
        }

        $batch = $buffer->drain();

        if ($batch === null) {
            return;
        }

        $batch = app(NameCardinalityGuard::class)->filter($batch);

        if ($batch->isEmpty()) {
            return;
        }

        try {
            app(Transports::class)->driver()->send($batch);
            $stats->increment('accepted', $batch->eventCount());
            $stats->increment('flushes');
        } catch (Throwable $e) {
            $this->fallback($batch, $e);
        }
    }

    private function fallback(Batch $batch, Throwable $original): void
    {
        $fallback = config('tendril.transport.fallback');
        $primary = config('tendril.transport.driver');

        if (is_string($fallback) && $fallback !== '' && $fallback !== $primary) {
            try {
                app(Transports::class)->driver($fallback)->send($batch);
                app(IngestionStats::class)->increment('accepted', $batch->eventCount());
                FailOpen::report($original, 'transport', 0);

                return;
            } catch (Throwable $e) {
                FailOpen::report($e, 'transport_fallback', $batch->eventCount());

                return;
            }
        }

        FailOpen::report($original, 'transport', $batch->eventCount());
    }
}
