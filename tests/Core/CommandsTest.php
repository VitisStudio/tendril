<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Events\HealthThresholdExceeded;
use VitisStudio\Tendril\Core\Support\Tables;

it('runs the doctor with machine-readable output and alert events', function () {
    $alerts = [];
    Illuminate\Support\Facades\Event::listen(HealthThresholdExceeded::class, function ($e) use (&$alerts) {
        $alerts[] = $e->result->name;
    });

    $this->artisan('tendril:doctor', ['--json' => true, '--alert' => true])->assertSuccessful();

    $names = collect(app(VitisStudio\Tendril\Core\Diagnostics\Diagnostics::class)->run())->pluck('name')->all();

    expect($names)->toContain('connection', 'migrations', 'transport', 'presence', 'retention', 'ingestion', 'dead_letters', 'aggregation_backlog', 'event_growth', 'cardinality', 'storage', 'privacy', 'aggregation_freshness', 'property_cardinality', 'timescale');
});

it('fails the doctor when migrations are missing', function () {
    // Point Tendril at an empty database instead of dropping tables (DDL
    // would commit the test transaction on MySQL).
    config([
        'database.connections.tendril_empty' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'tendril.database.connection' => 'tendril_empty',
    ]);

    $this->artisan('tendril:doctor')->assertFailed();
});

it('inspects an event catalog entry', function () {
    $at = CarbonImmutable::now('UTC')->subHour();
    writeEvent('report.exported', $at, ['properties' => ['format' => 'xlsx', 'rows' => 10]]);
    writeEvent('report.exported', $at, ['properties' => ['format' => 'csv'], 'version' => 2]);

    $this->artisan('tendril:inspect', ['event' => 'report.exported'])
        ->expectsOutputToContain('report.exported: 2 events')
        ->assertSuccessful();

    $report = app(VitisStudio\Tendril\Core\Diagnostics\EventInspector::class)->inspect('report.exported');

    expect($report['versions'])->toBe([1 => 1, 2 => 1])
        ->and($report['schema']['format']['distinct'])->toBe(2)
        ->and($report['schema']['rows']['types'])->toBe(['int']);
});

it('queues an audited erasure from the command line', function () {
    writeEvent('x.y', CarbonImmutable::now('UTC'), actor('77'));

    $this->artisan('tendril:erase', ['--actor-type' => 'App\\Models\\User', '--actor-id' => '77', '--force' => true])->assertSuccessful();

    expect(storedEvents()->where('actor_id', '77'))->toHaveCount(0);
});

it('rebuilds touchpoints from session snapshots', function () {
    $this->get('/?utm_source=google&utm_medium=cpc');
    db()->table(Tables::TOUCHPOINTS)->delete();

    $this->artisan('tendril:rebuild-touchpoints')->assertSuccessful();

    expect(db()->table(Tables::TOUCHPOINTS)->sole()->source)->toBe('google');
});

it('describes storage and prepares partitions', function () {
    $this->artisan('tendril:partitions', ['--describe' => true])
        ->expectsOutputToContain('Adapter:')
        ->assertSuccessful();
});
