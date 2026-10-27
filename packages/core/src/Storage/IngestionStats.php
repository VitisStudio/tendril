<?php

namespace VitisStudio\Tendril\Core\Storage;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Cheap per-day counters for self-health (accepted, rejected, dropped,
 * failed, persisted, duplicates, dead_lettered, flushes, lag). Stored in the
 * cache, recorded per flush/batch (never per event) and always best effort.
 */
final class IngestionStats
{
    public const array METRICS = [
        'accepted', 'rejected', 'dropped', 'failed', 'persisted', 'duplicates',
        'dead_lettered', 'flushes', 'batches', 'lag_ms_total', 'lag_ms_max',
    ];

    public function increment(string $metric, int $by = 1, ?string $day = null): void
    {
        if ($by <= 0 || ! $this->enabled()) {
            return;
        }

        try {
            $store = $this->store();
            $key = $this->key($metric, $day);
            $store->add($key, 0, now()->addDays(8));
            $store->increment($key, $by);
        } catch (Throwable) {
            // Stats never break ingestion.
        }
    }

    public function max(string $metric, int $value, ?string $day = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $store = $this->store();
            $key = $this->key($metric, $day);
            $current = $store->get($key);

            if (! is_numeric($current) || (int) $current < $value) {
                $store->put($key, $value, now()->addDays(8));
            }
        } catch (Throwable) {
            // Best effort.
        }
    }

    /**
     * @return array<string, int>
     */
    public function day(?string $day = null): array
    {
        $out = [];

        foreach (self::METRICS as $metric) {
            try {
                $value = $this->store()->get($this->key($metric, $day));
                $out[$metric] = is_numeric($value) ? (int) $value : 0;
            } catch (Throwable) {
                $out[$metric] = 0;
            }
        }

        return $out;
    }

    /**
     * Sum of the last N days (today included).
     *
     * @return array<string, int>
     */
    public function summary(int $days = 1): array
    {
        $totals = array_fill_keys(self::METRICS, 0);

        for ($i = 0; $i < max(1, $days); $i++) {
            foreach ($this->day(gmdate('Y-m-d', time() - $i * 86400)) as $metric => $value) {
                $totals[$metric] = $metric === 'lag_ms_max' ? max($totals[$metric], $value) : $totals[$metric] + $value;
            }
        }

        return $totals;
    }

    private function key(string $metric, ?string $day): string
    {
        return 'tendril:stats:'.($day ?? gmdate('Y-m-d')).':'.$metric;
    }

    private function enabled(): bool
    {
        return (bool) config('tendril.stats.enabled', true);
    }

    private function store(): Repository
    {
        $name = config('tendril.stats.cache_store');

        return app('cache')->store(is_string($name) && $name !== '' ? $name : null);
    }
}
