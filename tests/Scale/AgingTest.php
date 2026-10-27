<?php

use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Core\Testing\SyntheticDataset;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

/**
 * Two years of sparse history on every database: aggregation, pruning and
 * dashboard queries must stay correct and bounded as data ages. The full
 * benchmark lives in LoadTest (group "load").
 */
it('keeps two years of history correct through aggregation and retention', function () {
    $start = Instant::day(Instant::now())->subDays(729);

    $summary = (new SyntheticDataset([
        'tenants' => 2,
        'actors_per_tenant' => 3,
        'visitors_per_day' => 0,
        'days' => 730,
        'start' => $start,
        'activity' => 0.04,
        'burst' => 1,
    ], seed: 730))->generate();

    $started = microtime(true);
    $result = aggregate();
    $aggregationSeconds = microtime(true) - $started;

    $metrics = app(ProductMetrics::class);
    $firstYear = $metrics->activeActors($start, $start->addDays(364), TenantScope::all());
    $events = db()->table(Tables::EVENTS)->count();
    $dailyRollupTotal = (int) db()->table(Tables::ROLLUPS)->where('granularity', 'day')->where('kind', 'event')->sum('count');

    expect($summary['events'])->toBe($events)
        ->and($dailyRollupTotal)->toBe($events)
        ->and($result->days)->toBeGreaterThan(20)
        ->and($firstYear)->toBeGreaterThan(0);

    config(['tendril.retention.raw_days' => 365]);
    $this->artisan('tendril:prune')->assertSuccessful();

    expect(db()->table(Tables::EVENTS)->where('occurred_at', '<', db()->time(Instant::day(Instant::now())->subDays(365)))->count())->toBe(0)
        ->and($metrics->activeActors($start, $start->addDays(364), TenantScope::all()))->toBe($firstYear)
        ->and((int) db()->table(Tables::ROLLUPS)->where('granularity', 'day')->where('kind', 'event')->sum('count'))->toBe($dailyRollupTotal);

    fwrite(STDERR, sprintf("\n[aging] %d events over 730 days, aggregation %.2fs\n", $events, $aggregationSeconds));
});
