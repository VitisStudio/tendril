<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Product\Cardinality\CardinalityGuard;
use VitisStudio\Tendril\Product\Journeys\JourneyAnalyzer;

it('finds the most common paths after a starting event within sessions', function () {
    $t = CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC');

    foreach ([['reports.show', 'report.exported'], ['reports.show', 'report.exported'], ['billing.index']] as $i => $path) {
        $session = (string) Str::ulid();
        writeEvent('auth.login', $t->addMinutes($i * 10), ['session_id' => $session]);

        foreach ($path as $j => $step) {
            $name = str_contains($step, '.') && ! str_contains($step, 'report.') ? 'page.viewed' : $step;
            writeEvent($name, $t->addMinutes($i * 10 + $j + 1), ['session_id' => $session, 'context' => $name === 'page.viewed' ? ['route' => $step] : []]);
        }
    }

    $paths = app(JourneyAnalyzer::class)->next('auth.login', $t->startOfDay(), $t->endOfDay(), TenantScope::all(), depth: 2);

    expect($paths[0])->toBe(['path' => ['page:reports.show', 'report.exported'], 'sessions' => 2])
        ->and($paths[1])->toBe(['path' => ['page:billing.index'], 'sessions' => 1]);
});

it('flags exploding event names and high-cardinality properties', function () {
    config(['tendril-product.cardinality.max_distinct_values' => 5, 'tendril-product.cardinality.max_distinct_ratio' => 0.5]);
    $t = CarbonImmutable::now('UTC')->subHours(2);

    for ($i = 0; $i < 20; $i++) {
        writeEvent('order.completed', $t->addSeconds($i), ['properties' => ['order_id' => "ord-{$i}", 'currency' => 'USD']]);
    }

    aggregate();

    $warnings = app(CardinalityGuard::class)->propertyWarnings();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['event'])->toBe('order.completed')
        ->and($warnings[0]['property'])->toBe('order_id')
        ->and(app(CardinalityGuard::class)->distinctEventNames())->toBe(1);
});

it('rejects new event names beyond the daily cardinality cap at flush time', function () {
    config(['tendril.limits.max_distinct_names_per_day' => 3]);

    foreach (range(1, 5) as $i) {
        VitisStudio\Tendril\Core\Facades\Tendril::track("user.{$i}.viewed");
    }
    VitisStudio\Tendril\Core\Facades\Tendril::track('user.1.viewed');
    VitisStudio\Tendril\Core\Facades\Tendril::flush();

    expect(storedEvents()->pluck('name')->unique()->sort()->values()->all())->toBe(['user.1.viewed', 'user.2.viewed', 'user.3.viewed'])
        ->and(storedEvents('user.1.viewed'))->toHaveCount(2);
});
