<?php

namespace VitisStudio\Tendril\Core\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Contracts\Transport;

/**
 * In-memory transport returned by Tendril::fake() for host application tests.
 */
final class RecordingTransport implements Transport
{
    /** @var list<Batch> */
    private array $batches = [];

    public function send(Batch $batch): void
    {
        $this->batches[] = $batch;
    }

    /**
     * @return list<Batch>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(?string $name = null): array
    {
        $events = [];

        foreach ($this->batches as $batch) {
            foreach ($batch->events as $event) {
                if ($name === null || ($event['name'] ?? null) === $name) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $callback
     */
    public function assertTracked(string $name, ?Closure $callback = null, ?int $times = null): void
    {
        $matching = array_filter($this->events($name), fn (array $event) => $callback === null || $callback($event));

        if ($times !== null) {
            Assert::assertCount($times, $matching, "Expected [{$name}] to be tracked {$times} times.");

            return;
        }

        Assert::assertNotEmpty($matching, "Expected [{$name}] to be tracked.");
    }

    public function assertNotTracked(string $name): void
    {
        Assert::assertEmpty($this->events($name), "Unexpected [{$name}] event tracked.");
    }

    public function assertNothingTracked(): void
    {
        Assert::assertEmpty($this->events(), 'Expected no events to be tracked.');
    }
}
