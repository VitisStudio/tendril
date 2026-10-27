<?php

namespace VitisStudio\Tendril\Core\Transport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Storage\DeadLetters;

/**
 * Persists one request's batch. Retries are safe: event inserts are idempotent
 * on the dedupe key, session/alias operations are monotonic.
 */
final class PersistBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /**
     * @param  array<string, mixed>  $batch
     */
    public function __construct(public array $batch)
    {
        $this->tries = max(1, (int) config('tendril.transport.queue.tries', 5));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('tendril.transport.queue.backoff', [5, 30, 120])));
    }

    public function handle(BatchWriter $writer): void
    {
        $writer->write(Batch::fromArray($this->batch));
    }

    public function failed(?Throwable $exception = null): void
    {
        app(DeadLetters::class)->storeBatch(
            Batch::fromArray($this->batch),
            'queue_failed: '.($exception?->getMessage() ?? 'unknown'),
            $this->tries,
        );
    }
}
