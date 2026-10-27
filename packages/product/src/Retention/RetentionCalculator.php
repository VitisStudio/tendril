<?php

namespace VitisStudio\Tendril\Product\Retention;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Retention matrices from the actor-activity table (distinct actors, never rollups).
 *
 * Actors are grouped by the period of their first-ever activity (within the
 * scope) falling in [from, to]; cell k counts cohort members active in
 * period (start + k). Periods: day, week (ISO, Monday) or month, UTC.
 */
final class RetentionCalculator
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param  array<string, ActorRef>|null  $restrictTo  cohort members (actor key => actor)
     * @return list<array{cohort: string, size: int, counts: list<int>, rates: list<float>}>
     */
    public function matrix(
        CarbonImmutable $from,
        CarbonImmutable $to,
        TenantScope $scope,
        string $period = 'week',
        int $periods = 8,
        ?array $restrictTo = null,
        ?string $returnEvent = null,
        string $returnKind = 'event',
    ): array {
        if (! in_array($period, ['day', 'week', 'month'], true)) {
            throw new InvalidArgumentException('Retention period must be day, week or month.');
        }

        $periods = max(1, min(52, $periods));
        $date = $this->db->wrap('date');

        $firsts = $scope->applyToDerived($this->db->table(Tables::ACTIVITY))
            ->where('kind', 'any')
            ->where('actor_type', '<>', ActorRef::ANONYMOUS)
            ->select(['actor_type', 'actor_id'])
            ->selectRaw("min({$date}) as first_date")
            ->groupBy(['actor_type', 'actor_id'])
            ->havingRaw("min({$date}) >= ? and min({$date}) <= ?", [$from->toDateString(), $to->toDateString()])
            ->get();

        /** @var array<string, string> $cohortOf actor key => cohort period start */
        $cohortOf = [];
        $sizes = [];

        foreach ($firsts as $row) {
            $key = $row->actor_type.'|'.$row->actor_id;

            if ($restrictTo !== null && ! isset($restrictTo[$key])) {
                continue;
            }

            $start = self::periodStart(CarbonImmutable::parse(substr((string) $row->first_date, 0, 10), 'UTC'), $period)->toDateString();
            $cohortOf[$key] = $start;
            $sizes[$start] = ($sizes[$start] ?? 0) + 1;
        }

        if ($cohortOf === []) {
            return [];
        }

        $lastDay = self::periodStart($to, $period);
        $lastDay = self::advance($lastDay, $period, $periods)->subDay();

        $activity = $scope->applyToDerived($this->db->table(Tables::ACTIVITY))
            ->where('kind', $returnEvent === null ? 'any' : $returnKind)
            ->where('subject', $returnEvent ?? '')
            ->where('actor_type', '<>', ActorRef::ANONYMOUS)
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $lastDay->toDateString())
            ->select(['actor_type', 'actor_id', 'date'])
            ->distinct()
            ->cursor();

        /** @var array<string, array<int, array<string, true>>> $seen cohort => offset => actor set */
        $seen = [];

        foreach ($activity as $row) {
            $key = $row->actor_type.'|'.$row->actor_id;

            if (! isset($cohortOf[$key])) {
                continue;
            }

            $cohortStart = CarbonImmutable::parse($cohortOf[$key], 'UTC');
            $activeStart = self::periodStart(CarbonImmutable::parse(substr((string) $row->date, 0, 10), 'UTC'), $period);
            $offset = self::offset($cohortStart, $activeStart, $period);

            if ($offset >= 0 && $offset < $periods) {
                $seen[$cohortOf[$key]][$offset][$key] = true;
            }
        }

        ksort($sizes);
        $out = [];

        foreach ($sizes as $cohort => $size) {
            $counts = [];
            $rates = [];

            for ($k = 0; $k < $periods; $k++) {
                $count = count($seen[$cohort][$k] ?? []);
                $counts[] = $count;
                $rates[] = round($count / $size, 4);
            }

            $out[] = ['cohort' => $cohort, 'size' => $size, 'counts' => $counts, 'rates' => $rates];
        }

        return $out;
    }

    public static function periodStart(CarbonImmutable $date, string $period): CarbonImmutable
    {
        $date = $date->utc()->startOfDay();

        return match ($period) {
            'week' => $date->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $date->startOfMonth(),
            default => $date,
        };
    }

    public static function advance(CarbonImmutable $date, string $period, int $n): CarbonImmutable
    {
        return match ($period) {
            'week' => $date->addWeeks($n),
            'month' => $date->addMonthsNoOverflow($n),
            default => $date->addDays($n),
        };
    }

    public static function offset(CarbonImmutable $cohortStart, CarbonImmutable $activeStart, string $period): int
    {
        return match ($period) {
            'week' => intdiv((int) round(($activeStart->getTimestamp() - $cohortStart->getTimestamp()) / 86400), 7),
            'month' => ($activeStart->year - $cohortStart->year) * 12 + ($activeStart->month - $cohortStart->month),
            default => (int) round(($activeStart->getTimestamp() - $cohortStart->getTimestamp()) / 86400),
        };
    }
}
