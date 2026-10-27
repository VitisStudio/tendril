<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Core\Storage\Maintenance\MySqlPartitionMaintenance;
use VitisStudio\Tendril\Core\Storage\Maintenance\PostgresPartitionMaintenance;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

beforeEach(function () {
    onlyOn('pgsql', 'mysql');
    $this->freshSchema(['tendril.database.partitioned' => true]);
});

it('creates the events table natively partitioned and picks the matching adapter', function () {
    $adapter = app(MaintenanceManager::class)->adapter();

    expect($adapter)->toBeInstanceOf(driver() === 'pgsql' ? PostgresPartitionMaintenance::class : MySqlPartitionMaintenance::class)
        ->and($adapter->capabilities()['partitioned'])->toBeTrue();
});

it('prepares monthly partitions ahead and drops whole old partitions on prune', function () {
    $this->travelTo(CarbonImmutable::parse('2025-01-15', 'UTC'));
    $adapter = app(MaintenanceManager::class)->adapter();
    $early = $adapter->prepare(CarbonImmutable::parse('2025-04-01', 'UTC'));
    $this->travelBack();
    $current = $adapter->prepare(Instant::now()->startOfMonth()->addMonths(2));

    expect($early)->toContain('created '.(driver() === 'pgsql' ? 'tendril_events_p202501' : 'p202501'))
        ->and($current)->not->toBeEmpty();

    writeEvent('old.january', CarbonImmutable::parse('2025-01-10 10:00', 'UTC'));
    writeEvent('old.march', CarbonImmutable::parse('2025-03-10 10:00', 'UTC'));
    writeEvent('recent', Instant::now()->subHour());

    $removed = $adapter->pruneBefore(CarbonImmutable::parse('2025-03-01', 'UTC'), 100);
    $names = db()->table(Tables::EVENTS)->orderBy('occurred_at')->pluck('name')->all();

    expect($names)->toBe(['old.march', 'recent'])
        ->and($removed)->toBeGreaterThanOrEqual(0)
        ->and(collect($adapter->describe()['partitions'])->pluck('name')->implode(','))->not->toContain('202501');
});

it('keeps idempotent inserts on partitioned tables (dedupe key includes occurred_at)', function () {
    $at = Instant::now()->subMinutes(5);
    $id = (string) Str::ulid();
    $event = [
        'id' => $id, 'occurred_at' => Instant::serialize($at), 'received_at' => Instant::serialize($at), 'name' => 'retry.me',
        'version' => 1, 'dedupe_key' => 'req-1:retry', 'source' => 'app', 'environment' => 'testing', 'properties' => [], 'context' => [],
    ];

    $first = app(BatchWriter::class)->write(Batch::make([$event]));
    $retry = app(BatchWriter::class)->write(Batch::make([$event]));

    expect($first->inserted)->toBe(1)->and($retry->duplicates)->toBe(1)
        ->and(db()->table(Tables::EVENTS)->count())->toBe(1);
});
