<?php

namespace VitisStudio\Tendril\Core\Support;

use Throwable;
use VitisStudio\Tendril\Core\Events\IngestionFailed;
use VitisStudio\Tendril\Core\Storage\IngestionStats;

/**
 * Analytics must never break the host application. Every capture, hand-off
 * and persistence path funnels its failures through here.
 */
final class FailOpen
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $default
     * @return T
     */
    public static function run(callable $callback, string $stage, mixed $default = null, int $events = 0): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            self::report($e, $stage, $events);

            return $default;
        }
    }

    public static function report(Throwable $e, string $stage, int $events = 0): void
    {
        if (config('tendril.debug.throw')) {
            throw $e;
        }

        try {
            logger()->warning("Tendril {$stage} failed: {$e->getMessage()}", [
                'tendril_stage' => $stage,
                'tendril_events' => $events,
                'exception' => $e::class,
            ]);
        } catch (Throwable) {
            // Logging is best effort.
        }

        try {
            app(IngestionStats::class)->increment('failed', max(1, $events));
        } catch (Throwable) {
            // Stats are best effort.
        }

        self::dispatch(new IngestionFailed($stage, $e->getMessage(), $events, $e));
    }

    /**
     * Dispatch a Laravel event without letting host listeners break capture.
     */
    public static function dispatch(object $event): void
    {
        try {
            app('events')->dispatch($event);
        } catch (Throwable) {
            // Listener failures are the host's concern, never the request's.
        }
    }
}
