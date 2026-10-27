<?php

namespace VitisStudio\Tendril\Product\Cardinality;

use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Detects exploding event names (from daily rollups) and high-cardinality
 * property values (from a bounded sample of recent events per name) — the
 * usual symptom of ids, emails or timestamps leaking into analytics.
 */
final class CardinalityGuard
{
    public function __construct(private readonly Database $db) {}

    public function distinctEventNames(int $days = 7): int
    {
        return $this->db->table(Tables::ROLLUPS)
            ->where('granularity', 'day')
            ->where('kind', 'event')
            ->where('bucket_start', '>=', $this->db->time(Instant::day(Instant::now())->subDays($days)))
            ->distinct()
            ->count('dimension');
    }

    /**
     * @return list<array{event: string, property: string, distinct: int, sample: int}>
     */
    public function propertyWarnings(int $topEvents = 20): array
    {
        $sample = max(10, (int) config('tendril-product.cardinality.sample', 2000));
        $maxDistinct = (int) config('tendril-product.cardinality.max_distinct_values', 500);
        $maxRatio = (float) config('tendril-product.cardinality.max_distinct_ratio', 0.5);
        $count = $this->db->wrap('count');

        $names = $this->db->table(Tables::ROLLUPS)
            ->where('granularity', 'day')
            ->where('kind', 'event')
            ->where('bucket_start', '>=', $this->db->time(Instant::day(Instant::now())->subDays(1)))
            ->select('dimension')
            ->selectRaw("sum({$count}) as total")
            ->groupBy('dimension')
            ->orderByDesc('total')
            ->limit($topEvents)
            ->pluck('dimension');

        $warnings = [];

        foreach ($names as $name) {
            $values = [];
            $seen = 0;

            $rows = $this->db->table(Tables::EVENTS)
                ->where('name', $name)
                ->orderByDesc('occurred_at')
                ->limit($sample)
                ->pluck('properties');

            foreach ($rows as $json) {
                $properties = is_string($json) ? json_decode($json, true) : null;

                if (! is_array($properties)) {
                    continue;
                }

                $seen++;

                foreach ($properties as $key => $value) {
                    if (is_scalar($value)) {
                        $values[(string) $key][(string) $value] = true;
                    }
                }
            }

            foreach ($values as $key => $distinct) {
                $n = count($distinct);

                if ($seen > 0 && $n > $maxDistinct && ($n / $seen) > $maxRatio) {
                    $warnings[] = ['event' => (string) $name, 'property' => $key, 'distinct' => $n, 'sample' => $seen];
                }
            }
        }

        return $warnings;
    }
}
