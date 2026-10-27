<?php

use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Core\Testing\SyntheticDataset;
use VitisStudio\Tendril\Product\Funnels\FunnelDefinition;
use VitisStudio\Tendril\Product\Funnels\FunnelEngine;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;
use VitisStudio\Tendril\Product\Retention\RetentionCalculator;

/**
 * Release-qualification benchmark (spec §17): >= 2 years of synthetic data,
 * many tenants, skewed hot features, bursty Livewire traffic, anonymous to
 * known transitions. Excluded from the default run: `composer test:load`.
 * Budgets are overridable with TENDRIL_BUDGET_* environment variables and
 * every measurement is printed for the performance record.
 */
function measure(callable $callback): float
{
    $start = hrtime(true);
    $callback();

    return (hrtime(true) - $start) / 1e6;
}

function percentile(array $values, float $p): float
{
    sort($values);

    return $values[(int) max(0, ceil($p / 100 * count($values)) - 1)] ?? 0.0;
}

it('meets ingestion, aggregation, dashboard, pruning and backfill budgets at year two', function () {
    $days = (int) env('TENDRIL_LOAD_DAYS', 730);
    $start = Instant::day(Instant::now())->subDays($days - 1);
    $report = [];

    $report['generate_ms'] = measure(function () use ($start, $days, &$summary) {
        $summary = (new SyntheticDataset([
            'tenants' => (int) env('TENDRIL_LOAD_TENANTS', 20),
            'actors_per_tenant' => (int) env('TENDRIL_LOAD_ACTORS', 25),
            'visitors_per_day' => (int) env('TENDRIL_LOAD_VISITORS', 40),
            'days' => $days,
            'start' => $start,
            'activity' => 0.35,
            'burst' => 8,
            'flush_every' => 5000,
        ], seed: 17))->generate();
    });

    $report['events'] = $summary['events'];
    $report['ingest_events_per_second'] = round($summary['events'] / max(0.001, $report['generate_ms'] / 1000));

    // Request overhead: normalize + decide + buffer (persistence happens after
    // the response), measured against an in-memory transport.
    Tendril::fake();
    $overhead = [];

    for ($i = 0; $i < 500; $i++) {
        $overhead[] = measure(fn () => Tendril::track('bench.event', ['i' => $i, 'plan' => 'pro']));
    }

    Tendril::flush();
    config(['tendril.transport.driver' => 'sync']);
    $report['capture_p95_ms'] = percentile($overhead, 95);
    $report['capture_p99_ms'] = percentile($overhead, 99);

    $report['aggregate_ms'] = measure(fn () => aggregate());

    $metrics = app(ProductMetrics::class);
    $dashboard = [];

    foreach (range(1, 20) as $_) {
        $dashboard[] = measure(function () use ($metrics) {
            $today = Instant::day(Instant::now());
            $metrics->dau($today, TenantScope::all());
            $metrics->mau($today, TenantScope::all());
            $metrics->dailyActive($today->subDays(29), $today, TenantScope::all());
            $metrics->topFeatures($today->subDays(29), $today, TenantScope::all());
            $metrics->series('event', null, $today->subDays(29), $today, TenantScope::all());
        });
    }

    $report['dashboard_p95_ms'] = percentile($dashboard, 95);
    $report['retention_ms'] = measure(fn () => app(RetentionCalculator::class)->matrix(Instant::now()->subWeeks(12), Instant::now(), TenantScope::all()));
    $report['funnel_ms'] = measure(fn () => app(FunnelEngine::class)->evaluate(FunnelDefinition::fromArray(['definition' => [
        'steps' => [['event' => 'page.viewed'], ['event' => 'report.exported']],
        'window_seconds' => 3600,
    ]]), Instant::now()->subDays(30), Instant::now(), TenantScope::all()));

    $report['table_rows'] = [
        'events' => db()->table(Tables::EVENTS)->count(),
        'rollups' => db()->table(Tables::ROLLUPS)->count(),
        'activity' => db()->table(Tables::ACTIVITY)->count(),
        'sessions' => db()->table(Tables::SESSIONS)->count(),
    ];
    $report['storage'] = app(VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager::class)->adapter()->describe()['tables'] ?? [];

    $report['backfill_ms'] = measure(fn () => test()->artisan('tendril:backfill', ['--from' => $start->addDays($days - 60)->toDateString(), '--days' => 14, '--restart' => true]));

    config(['tendril.retention.raw_days' => 365]);
    $report['prune_ms'] = measure(fn () => test()->artisan('tendril:prune'));

    fwrite(STDERR, "\n[load] ".json_encode($report, JSON_PRETTY_PRINT)."\n");

    expect($report['capture_p99_ms'])->toBeLessThan((float) env('TENDRIL_BUDGET_CAPTURE_P99_MS', 5))
        ->and($report['dashboard_p95_ms'])->toBeLessThan((float) env('TENDRIL_BUDGET_DASHBOARD_P95_MS', 1500))
        ->and($report['table_rows']['activity'])->toBeLessThan($report['table_rows']['events']);
})->group('load');
