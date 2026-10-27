<?php

namespace VitisStudio\Tendril\Core\Capture;

use Throwable;
use VitisStudio\Tendril\Core\Events\EventRejected;
use VitisStudio\Tendril\Core\Storage\IngestionStats;
use VitisStudio\Tendril\Core\Support\FailOpen;

/**
 * Guards against exploding event names (e.g. "user.742.viewed"). Runs once
 * per flush, after the response, against a per-day set of names in the cache.
 */
final class NameCardinalityGuard
{
    public function filter(Batch $batch): Batch
    {
        $max = (int) config('tendril.limits.max_distinct_names_per_day', 1000);

        if ($max <= 0 || $batch->events === []) {
            return $batch;
        }

        try {
            $store = app('cache')->store(config('tendril.stats.cache_store') ?: null);
            $key = 'tendril:names:'.gmdate('Y-m-d');
            $known = $store->get($key, []);
            $known = is_array($known) ? $known : [];
            $changed = false;
            $kept = [];
            $rejected = 0;

            foreach ($batch->events as $event) {
                $name = (string) ($event['name'] ?? '');

                if (isset($known[$name])) {
                    $kept[] = $event;

                    continue;
                }

                if (count($known) < $max) {
                    $known[$name] = true;
                    $changed = true;
                    $kept[] = $event;

                    continue;
                }

                $rejected++;
                FailOpen::dispatch(new EventRejected($name, 'name_cardinality'));
            }

            if ($changed) {
                $store->put($key, $known, now()->addDays(2));
            }

            if ($rejected > 0) {
                app(IngestionStats::class)->increment('rejected', $rejected);
            }

            return $batch->withEvents($kept);
        } catch (Throwable) {
            return $batch;
        }
    }
}
