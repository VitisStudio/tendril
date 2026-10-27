<?php

namespace VitisStudio\Tendril\Core\Context;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Capture\Flusher;
use VitisStudio\Tendril\Core\Http\RequestLifecycle;
use VitisStudio\Tendril\Core\Support\FailOpen;

/**
 * Flush and reset at every unit-of-work boundary so long-lived processes
 * (queue workers, Horizon, Octane) never leak a previous user's identity,
 * campaign or buffered events into the next request or job.
 *
 * Jobs executed synchronously inside an HTTP request (sync queue) share the
 * request's unit of work and are left alone.
 */
final class LifecycleSubscriber
{
    public const array OCTANE_START = [
        'Laravel\Octane\Events\RequestReceived',
        'Laravel\Octane\Events\TaskReceived',
        'Laravel\Octane\Events\TickReceived',
    ];

    public const array OCTANE_END = [
        'Laravel\Octane\Events\RequestTerminated',
        'Laravel\Octane\Events\TaskTerminated',
        'Laravel\Octane\Events\TickTerminated',
    ];

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        $map = [
            JobProcessing::class => 'jobStarting',
            JobProcessed::class => 'jobFinished',
            JobFailed::class => 'jobFinished',
            JobExceptionOccurred::class => 'jobFinished',
            CommandStarting::class => 'commandStarting',
            CommandFinished::class => 'commandFinished',
        ];

        foreach (self::OCTANE_START as $event) {
            $map[$event] = 'octaneStarting';
        }

        foreach (self::OCTANE_END as $event) {
            $map[$event] = 'octaneFinished';
        }

        return $map;
    }

    public function jobStarting(JobProcessing $event): void
    {
        if ($this->inline($event->connectionName)) {
            return;
        }

        FailOpen::run(function (): void {
            app(Buffer::class)->reset();
            $store = app(ContextStore::class);
            // Context was hydrated from the job payload by Laravel; keep the
            // propagated request/trace ids but mark the source.
            $store->ensureRoot('queue');
            $store->setSource('queue');
        }, 'job_start');
    }

    public function jobFinished(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        if ($this->inline($event->connectionName)) {
            return;
        }

        $this->finish();
    }

    public function commandStarting(CommandStarting $event): void
    {
        if (app(RequestLifecycle::class)->active() || $event->command === 'queue:work' || $event->command === 'horizon:work') {
            return;
        }

        FailOpen::run(fn () => app(ContextStore::class)->ensureRoot('console'), 'command_start');
    }

    public function commandFinished(CommandFinished $event): void
    {
        if (app(RequestLifecycle::class)->active()) {
            return;
        }

        $this->finish();
    }

    public function octaneStarting(object $event): void
    {
        FailOpen::run(function (): void {
            app(Buffer::class)->reset();
            app(ContextStore::class)->clear();
        }, 'octane_start');
    }

    public function octaneFinished(object $event): void
    {
        $this->finish();
    }

    private function finish(): void
    {
        FailOpen::run(fn () => app(Flusher::class)->flush(), 'flush');
        FailOpen::run(function (): void {
            app(ContextStore::class)->clear();
            app(Buffer::class)->reset();
        }, 'reset');
    }

    private function inline(?string $connection): bool
    {
        return app(RequestLifecycle::class)->active()
            || ($connection !== null && config("queue.connections.{$connection}.driver") === 'sync');
    }
}
