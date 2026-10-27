<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Identity\TenantRef;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Core\Testing\SyntheticDataset;
use VitisStudio\Tendril\Product\Features\FeatureRepository;
use VitisStudio\Tendril\Product\Funnels\FunnelDefinition;
use VitisStudio\Tendril\Product\Funnels\FunnelEngine;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;
use VitisStudio\Tendril\Product\Retention\RetentionCalculator;

/**
 * Recomputes every metric directly from raw events with deliberately naive,
 * independent code and asserts the rollup/activity answers match exactly.
 * This is the test that catches "summed uniques" and late-event bugs; run
 * it on every aggregation change.
 */
final class Oracle
{
    /** @var list<array{id: string, at: CarbonImmutable, day: string, name: string, actor: ?string, anon: ?string, tenant: ?string, route: ?string, component: ?string, action: ?string}> */
    public array $events = [];

    /** @var array<string, list<array{actor: string, from: CarbonImmutable, to: ?CarbonImmutable}>> */
    private array $aliases = [];

    public function __construct()
    {
        foreach (db()->table(Tables::ALIASES)->orderBy('valid_from')->get() as $alias) {
            $this->aliases[$alias->anonymous_id][] = [
                'actor' => $alias->actor_type.'|'.$alias->actor_id,
                'from' => Instant::parse($alias->valid_from),
                'to' => Instant::tryParse($alias->valid_to),
            ];
        }

        foreach (db()->table(Tables::EVENTS)->orderBy('occurred_at')->orderBy('id')->get() as $row) {
            $context = is_string($row->context) ? (json_decode($row->context, true) ?: []) : [];
            $at = Instant::parse($row->occurred_at);

            $this->events[] = [
                'id' => (string) $row->id,
                'at' => $at,
                'day' => $at->toDateString(),
                'name' => $row->name,
                'actor' => $row->actor_id !== null ? $row->actor_type.'|'.$row->actor_id : null,
                'anon' => $row->anonymous_id,
                'tenant' => $row->tenant_id !== null ? $row->tenant_type.'|'.$row->tenant_id : null,
                'route' => $context['route'] ?? null,
                'component' => $context['component'] ?? null,
                'action' => $context['action'] ?? null,
            ];
        }
    }

    /**
     * Known subject (actor or alias-resolved actor), else null.
     */
    public function known(array $event): ?string
    {
        if ($event['actor'] !== null) {
            return $event['actor'];
        }

        $match = null;

        foreach ($this->aliases[$event['anon']] ?? [] as $range) {
            if ($range['from']->lessThanOrEqualTo($event['at']) && ($range['to'] === null || $event['at']->lessThan($range['to']))) {
                $match = $range['actor'];
            }
        }

        return $match;
    }

    /**
     * @return array<string, true>
     */
    public function actors(string $fromDay, string $toDay, ?string $tenant = null, ?callable $filter = null): array
    {
        $set = [];

        foreach ($this->events as $event) {
            if ($event['day'] < $fromDay || $event['day'] > $toDay) {
                continue;
            }

            if ($tenant !== null && $event['tenant'] !== $tenant) {
                continue;
            }

            if ($filter !== null && ! $filter($event)) {
                continue;
            }

            if (($subject = $this->known($event)) !== null) {
                $set[$subject] = true;
            }
        }

        return $set;
    }
}

beforeEach(function () {
    $this->start = CarbonImmutable::parse('2026-06-01', 'UTC');
    $this->end = $this->start->addDays(27);

    (new SyntheticDataset([
        'tenants' => 3,
        'actors_per_tenant' => 6,
        'visitors_per_day' => 3,
        'days' => 28,
        'start' => $this->start,
        'activity' => 0.55,
    ], seed: 2026))->generate();

    aggregate();

    $this->oracle = new Oracle;
    $this->metrics = app(ProductMetrics::class);
});

it('matches DAU for every day, globally and per tenant', function () {
    $tenant = new TenantRef('App\\Models\\Team', '2');
    $global = $this->metrics->dailyActive($this->start, $this->end, TenantScope::all());
    $scoped = $this->metrics->dailyActive($this->start, $this->end, TenantScope::tenant($tenant));

    foreach ($global as $day => $count) {
        expect($count)->toBe(count($this->oracle->actors($day, $day)), "DAU {$day}")
            ->and($scoped[$day])->toBe(count($this->oracle->actors($day, $day, 'App\\Models\\Team|2')), "tenant DAU {$day}");
    }
});

it('matches WAU and MAU (never summed from daily numbers)', function () {
    $last = $this->end->toDateString();

    expect($this->metrics->wau($this->end, TenantScope::all()))->toBe(count($this->oracle->actors($this->end->subDays(6)->toDateString(), $last)))
        ->and($this->metrics->mau($this->end, TenantScope::all()))->toBe(count($this->oracle->actors($this->end->subDays(27)->toDateString(), $last)))
        ->and($this->metrics->mau($this->end, TenantScope::all()))->toBeLessThan(array_sum($this->metrics->dailyActive($this->start, $this->end, TenantScope::all())));
});

it('matches feature uniques and event counts', function () {
    $feature = app(FeatureRepository::class)->find('reporting');
    $from = $this->start->toDateString();
    $to = $this->end->toDateString();

    $uniques = $this->oracle->actors($from, $to, null, fn ($e) => $feature->matches($e['name'], $e['route'], $e['component'], $e['action']));

    expect($this->metrics->feature('reporting', $this->start, $this->end, TenantScope::all())['uniques'])->toBe(count($uniques));

    $series = $this->metrics->series('event', 'page.viewed', $this->start, $this->end, TenantScope::all());

    foreach ($series as $day => $count) {
        $expected = count(array_filter($this->oracle->events, fn ($e) => $e['day'] === $day && $e['name'] === 'page.viewed'));
        expect($count)->toBe($expected, "page views {$day}");
    }

    expect(array_sum($series))->toBeGreaterThan(0);
});

it('matches funnel completion computed naively from raw events', function () {
    $funnel = FunnelDefinition::fromArray(['key' => 'export', 'definition' => [
        'steps' => [
            ['event' => 'page.viewed', 'where' => ['all' => [['field' => 'context.route', 'op' => 'eq', 'value' => 'reports.show']]]],
            ['event' => 'report.exported'],
        ],
        'window_seconds' => 3600,
    ]]);

    $from = $this->start;
    $to = $this->start->addDays(14);
    $result = app(FunnelEngine::class)->evaluate($funnel, $from, $to, TenantScope::all(), includeAnonymous: false);

    // Naive oracle: a subject converts if some qualifying entry is followed by an export within an hour.
    $entered = [];
    $completed = [];
    $exports = array_values(array_filter($this->oracle->events, fn ($e) => $e['name'] === 'report.exported'));

    foreach ($this->oracle->events as $entry) {
        $subject = $this->oracle->known($entry);

        if ($subject === null || $entry['name'] !== 'page.viewed' || $entry['route'] !== 'reports.show' || $entry['at']->lessThan($from) || ! $entry['at']->lessThan($to)) {
            continue;
        }

        $entered[$subject] = true;

        foreach ($exports as $next) {
            if ($this->oracle->known($next) === $subject
                && [$next['at']->getTimestamp(), (int) $next['at']->format('u'), $next['id']] > [$entry['at']->getTimestamp(), (int) $entry['at']->format('u'), $entry['id']]
                && $next['at']->lessThanOrEqualTo($entry['at']->addSeconds(3600))) {
                $completed[$subject] = true;
            }
        }
    }

    expect($result->entered)->toBe(count($entered))
        ->and($result->completed)->toBe(count($completed))
        ->and($result->steps[1]['count'])->toBe(count($completed))
        ->and($result->completed)->toBeGreaterThan(0);
});

it('matches weekly retention computed naively', function () {
    $matrix = app(RetentionCalculator::class)->matrix($this->start, $this->end, TenantScope::all(), 'week', 4);

    // Naive: first active day per known actor (all history), week buckets from Monday.
    $first = [];
    $activeDays = [];

    foreach ($this->oracle->events as $event) {
        if (($subject = $this->oracle->known($event)) === null) {
            continue;
        }

        $first[$subject] = min($first[$subject] ?? '9999-12-31', $event['day']);
        $activeDays[$subject][$event['day']] = true;
    }

    $week = fn (string $day) => CarbonImmutable::parse($day, 'UTC')->startOfWeek(CarbonImmutable::MONDAY);
    $expected = [];

    foreach ($first as $subject => $day) {
        if ($day < $this->start->toDateString() || $day > $this->end->toDateString()) {
            continue;
        }

        $cohort = $week($day)->toDateString();
        $expected[$cohort]['size'] = ($expected[$cohort]['size'] ?? 0) + 1;

        for ($k = 0; $k < 4; $k++) {
            $start = $week($day)->addWeeks($k);
            $hit = false;

            foreach (array_keys($activeDays[$subject]) as $active) {
                if ($active >= $start->toDateString() && $active < $start->addWeek()->toDateString()) {
                    $hit = true;

                    break;
                }
            }

            $expected[$cohort]['counts'][$k] = ($expected[$cohort]['counts'][$k] ?? 0) + ($hit ? 1 : 0);
        }
    }

    ksort($expected);

    expect(array_column($matrix, 'cohort'))->toBe(array_keys($expected));

    foreach ($matrix as $row) {
        expect($row['size'])->toBe($expected[$row['cohort']]['size'])
            ->and($row['counts'])->toBe(array_values($expected[$row['cohort']]['counts']));
    }
});

it('stays exact after late events and a full rebuild', function () {
    $late = $this->start->addDays(3)->setTime(23, 59, 59);
    writeEvent('page.viewed', $late, actor('99999', '1') + ['context' => ['route' => 'reports.show']]);
    aggregate();

    $this->artisan('tendril:rebuild-rollups', ['--run' => true])->assertSuccessful();

    $oracle = new Oracle;
    $day = $late->toDateString();

    expect($this->metrics->dau($late, TenantScope::all()))->toBe(count($oracle->actors($day, $day)))
        ->and($this->metrics->dailyActive($late, $late, TenantScope::all())[$day])->toBe(count($oracle->actors($day, $day)));
});
