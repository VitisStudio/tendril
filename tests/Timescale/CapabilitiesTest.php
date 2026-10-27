<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Timescale\Capabilities;
use VitisStudio\Tendril\Timescale\CapabilityDetector;
use VitisStudio\Tendril\Timescale\TimescaleMaintenance;

function probe(?string $version, ?string $license, array $functions, bool $hypertable = false): array
{
    return ['version' => $version, 'license' => $license, 'functions' => $functions, 'events_hypertable' => $hypertable];
}

it('never assumes TSL features on Apache-licensed builds (e.g. Neon)', function () {
    $apache = Capabilities::fromProbe(probe('2.14.2', 'apache', ['create_hypertable', 'drop_chunks', 'add_retention_policy']));

    expect($apache->hypertable)->toBeTrue()
        ->and($apache->compression)->toBeFalse()
        ->and($apache->continuousAggregates)->toBeFalse()
        ->and($apache->tiering)->toBeFalse();

    $community = Capabilities::fromProbe(probe('2.17.0', 'timescale', ['create_hypertable', 'add_compression_policy']));

    expect($community->compression)->toBeTrue()->and($community->continuousAggregates)->toBeTrue();

    $missing = Capabilities::fromProbe(probe(null, null, []));

    expect($missing->installed)->toBeFalse()->and($missing->hypertable)->toBeFalse();
});

it('reports unavailable on non-PostgreSQL connections', function () {
    onlyOn('mysql', 'sqlite');

    expect(app(CapabilityDetector::class)->detect()->installed)->toBeFalse();
});

it('registers as a storage adapter and falls back to portable pruning when not a hypertable', function () {
    config(['tendril.storage.adapter' => 'timescale', 'tendril-timescale.compress_after' => '30 days']);
    app(CapabilityDetector::class)->usingProbe(fn () => probe('2.14.2', 'apache', ['create_hypertable'], false));

    $adapter = app(MaintenanceManager::class)->adapter();
    $old = CarbonImmutable::parse('2025-01-01 00:00:00', 'UTC');

    writeEvent('old.event', $old);
    writeEvent('new.event', CarbonImmutable::now('UTC'));

    expect($adapter)->toBeInstanceOf(TimescaleMaintenance::class)
        ->and($adapter->capabilities()['license'])->toBe('apache')
        ->and($adapter->prepare(CarbonImmutable::now('UTC')))->toBe(['tendril_events is not a hypertable; run tendril:timescale:install'])
        ->and($adapter->pruneBefore(CarbonImmutable::parse('2026-01-01', 'UTC'), 100))->toBe(1)
        ->and(db()->table(Tables::EVENTS)->pluck('name')->all())->toBe(['new.event']);
});

it('skips compression when the edition does not support it', function () {
    config(['tendril-timescale.compress_after' => '30 days']);
    app(CapabilityDetector::class)->usingProbe(fn () => probe('2.14.2', 'apache', ['create_hypertable'], true));

    expect(app(TimescaleMaintenance::class)->prepare(CarbonImmutable::now('UTC')))
        ->toBe(['compression requested but not supported by this TimescaleDB edition; skipped']);
});

it('converts the events table into a hypertable on a real TimescaleDB', function () {
    onlyOn('pgsql');

    if (! app(CapabilityDetector::class)->detect()->hypertable) {
        $this->markTestSkipped('TimescaleDB is not installed on this PostgreSQL server.');
    }

    writeEvent('old.event', CarbonImmutable::parse('2025-01-01', 'UTC'));
    writeEvent('new.event', CarbonImmutable::now('UTC'));

    $maintenance = app(TimescaleMaintenance::class);
    $maintenance->install();

    expect(app(CapabilityDetector::class)->detect()->eventsIsHypertable)->toBeTrue()
        ->and($maintenance->pruneBefore(CarbonImmutable::parse('2026-01-01', 'UTC'), 100))->toBe(1)
        ->and(storedEvents()->pluck('name')->all())->toBe(['new.event']);
});
