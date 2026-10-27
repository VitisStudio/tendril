<?php

namespace VitisStudio\Tendril\Product\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Dashboard queries. Uniques come ONLY from tendril_actor_activity via
 * COUNT over DISTINCT subqueries; counts/sums come ONLY from tendril_rollups.
 * Nothing here reads raw events. Dates are UTC days.
 */
final class ProductMetrics
{
    public function __construct(private readonly Database $db) {}

    /**
     * Distinct actors active in [from, to] (inclusive dates).
     */
    public function activeActors(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, bool $includeAnonymous = false, string $kind = 'any', string $subject = ''): int
    {
        $inner = $this->activity($from, $to, $scope, $kind, $subject, $includeAnonymous)
            ->select(['actor_type', 'actor_id'])
            ->distinct();

        return $this->db->connection()->query()->fromSub($inner, 'actors')->count();
    }

    public function dau(CarbonImmutable $day, TenantScope $scope): int
    {
        return $this->activeActors($day, $day, $scope);
    }

    public function wau(CarbonImmutable $day, TenantScope $scope): int
    {
        return $this->activeActors($day->subDays(6), $day, $scope);
    }

    public function mau(CarbonImmutable $day, TenantScope $scope): int
    {
        return $this->activeActors($day->subDays(29), $day, $scope);
    }

    /**
     * Daily distinct actors per date.
     *
     * @return array<string, int> Y-m-d => count
     */
    public function dailyActive(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, bool $includeAnonymous = false, string $kind = 'any', string $subject = ''): array
    {
        $inner = $this->activity($from, $to, $scope, $kind, $subject, $includeAnonymous)
            ->select(['date', 'actor_type', 'actor_id'])
            ->distinct();

        $rows = $this->db->connection()->query()->fromSub($inner, 'daily')
            ->select('date')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $out = $this->emptyDays($from, $to);

        foreach ($rows as $row) {
            $out[substr((string) $row->date, 0, 10)] = (int) $row->aggregate;
        }

        return $out;
    }

    /**
     * @return array{uniques: int, active: int, adoption: float, frequency: float, events: int}
     */
    public function feature(string $feature, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $uniques = $this->activeActors($from, $to, $scope, false, 'feature', $feature);
        $active = $this->activeActors($from, $to, $scope);

        $actorDays = $this->db->connection()->query()->fromSub(
            $this->activity($from, $to, $scope, 'feature', $feature, false)->select(['date', 'actor_type', 'actor_id'])->distinct(),
            'feature_days',
        )->count();

        return [
            'uniques' => $uniques,
            'active' => $active,
            'adoption' => $active > 0 ? round($uniques / $active, 4) : 0.0,
            'frequency' => $uniques > 0 ? round($actorDays / $uniques, 2) : 0.0,
            'events' => $this->rollupTotal('feature', $feature, $from, $to, $scope),
        ];
    }

    /**
     * @return list<array{feature: string, uniques: int}>
     */
    public function topFeatures(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, int $limit = 10): array
    {
        $inner = $this->activity($from, $to, $scope, 'feature', null, false)
            ->select(['subject', 'actor_type', 'actor_id'])
            ->distinct();

        return array_values($this->db->connection()->query()->fromSub($inner, 'f')
            ->select('subject')
            ->selectRaw('count(*) as uniques')
            ->groupBy('subject')
            ->orderByDesc('uniques')
            ->orderBy('subject')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['feature' => (string) $r->subject, 'uniques' => (int) $r->uniques])
            ->all());
    }

    /**
     * Rollup counts per bucket for one dimension.
     *
     * @return array<string, int> bucket (ISO) => count
     */
    public function series(string $kind, ?string $dimension, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, string $granularity = 'day', bool $excludeCurrencyDimensions = false): array
    {
        $count = $this->db->wrap('count');
        $query = $this->rollups($kind, $dimension, $from, $to, $scope, $granularity);

        if ($excludeCurrencyDimensions) {
            $query->where('dimension', 'not like', '%:%');
        }

        $rows = $query
            ->select('bucket_start')
            ->selectRaw("sum({$count}) as total")
            ->groupBy('bucket_start')
            ->orderBy('bucket_start')
            ->get();

        $out = $granularity === 'day' ? $this->emptyDays($from, $to) : [];

        foreach ($rows as $row) {
            $bucket = Instant::parse($row->bucket_start);
            $out[$granularity === 'day' ? $bucket->toDateString() : $bucket->toIso8601String()] = (int) $row->total;
        }

        return $out;
    }

    public function rollupTotal(string $kind, ?string $dimension, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, string $column = 'count'): int
    {
        return (int) $this->rollups($kind, $dimension, $from, $to, $scope, 'day')->sum($column);
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    public function topEvents(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, int $limit = 20): array
    {
        $count = $this->db->wrap('count');

        return array_values($this->rollups('event', null, $from, $to, $scope, 'day')
            ->select('dimension')
            ->selectRaw("sum({$count}) as total")
            ->groupBy('dimension')
            ->orderByDesc('total')
            ->orderBy('dimension')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['name' => (string) $r->dimension, 'count' => (int) $r->total])
            ->all());
    }

    /**
     * @return array{sessions: int, bounced: int, bounce_rate: float, avg_duration_seconds: float}
     */
    public function sessions(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $sessions = $this->rollupTotal('session', 'started', $from, $to, $scope);
        $duration = $this->rollupTotal('session', 'started', $from, $to, $scope, 'sum');
        $bounced = $this->rollupTotal('session', 'bounced', $from, $to, $scope);

        return [
            'sessions' => $sessions,
            'bounced' => $bounced,
            'bounce_rate' => $sessions > 0 ? round($bounced / $sessions, 4) : 0.0,
            'avg_duration_seconds' => $sessions > 0 ? round($duration / $sessions, 1) : 0.0,
        ];
    }

    /**
     * Logins per active actor in the window.
     */
    public function loginFrequency(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): float
    {
        $logins = $this->rollupTotal('event', 'auth.login', $from, $to, $scope);
        $actors = $this->activeActors($from, $to, $scope, false, 'event', 'auth.login');

        return $actors > 0 ? round($logins / $actors, 2) : 0.0;
    }

    /**
     * New vs returning actors in [from, to]: new = first ever activity inside the window.
     *
     * @return array{new: int, returning: int}
     */
    public function newVersusReturning(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): array
    {
        $active = $this->activity($from, $to, $scope, 'any', '', false)->select(['actor_type', 'actor_id'])->distinct();

        $first = $scope->applyToDerived($this->db->table(Tables::ACTIVITY))
            ->where('kind', 'any')
            ->where('actor_type', '<>', ActorRef::ANONYMOUS)
            ->select(['actor_type', 'actor_id'])
            ->selectRaw('min('.$this->db->wrap('date').') as first_date')
            ->groupBy(['actor_type', 'actor_id']);

        $rows = $this->db->connection()->query()
            ->fromSub($active, 'a')
            ->joinSub($first, 'f', function ($join): void {
                $join->on('a.actor_type', '=', 'f.actor_type')->on('a.actor_id', '=', 'f.actor_id');
            })
            ->select('f.first_date')
            ->get();

        $new = 0;
        $returning = 0;
        $fromDate = $from->toDateString();

        foreach ($rows as $row) {
            substr((string) $row->first_date, 0, 10) >= $fromDate ? $new++ : $returning++;
        }

        return ['new' => $new, 'returning' => $returning];
    }

    /**
     * Actor directory for the Users page: last seen and active days.
     */
    public function actorsQuery(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope): Builder
    {
        return $this->activity($from, $to, $scope, 'any', '', false)
            ->select(['actor_type', 'actor_id'])
            ->selectRaw('max('.$this->db->wrap('date').') as last_seen, count(*) as active_days, sum('.$this->db->wrap('event_count').') as events')
            ->groupBy(['actor_type', 'actor_id']);
    }

    public function activity(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, string $kind = 'any', ?string $subject = '', bool $includeAnonymous = false): Builder
    {
        $query = $scope->applyToDerived($this->db->table(Tables::ACTIVITY))
            ->where('kind', $kind)
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString());

        if ($subject !== null) {
            $query->where('subject', $subject);
        }

        if (! $includeAnonymous) {
            $query->where('actor_type', '<>', ActorRef::ANONYMOUS);
        }

        return $query;
    }

    public function rollups(string $kind, ?string $dimension, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, string $granularity = 'day'): Builder
    {
        $start = $granularity === 'day' ? Instant::day($from) : Instant::hour($from);
        $end = $granularity === 'day' ? Instant::day($to)->addDay() : Instant::hour($to)->addHour();

        $query = $scope->applyToDerived($this->db->table(Tables::ROLLUPS))
            ->where('granularity', $granularity)
            ->where('kind', $kind)
            ->where('bucket_start', '>=', $this->db->time($start))
            ->where('bucket_start', '<', $this->db->time($end));

        if ($dimension !== null) {
            $query->where('dimension', $dimension);
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function emptyDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $day = Instant::day($from);
        $end = Instant::day($to);

        while ($day->lessThanOrEqualTo($end)) {
            $out[$day->toDateString()] = 0;
            $day = $day->addDay();
        }

        return $out;
    }
}
