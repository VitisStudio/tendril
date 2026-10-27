<?php

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Commands\BackfillCommand;
use VitisStudio\Tendril\Product\Features\FeatureDefinition;
use VitisStudio\Tendril\Product\Features\FeatureRepository;

it('aggregates dirty buckets from the command line', function () {
    writeEvent('x.y', CarbonImmutable::parse('2026-09-01 10:00', 'UTC'), actor('1'));

    $this->artisan('tendril:aggregate', ['--loop' => true])
        ->expectsOutputToContain('Aggregated')
        ->assertSuccessful();

    expect(db()->table(Tables::ROLLUPS)->where('dimension', 'x.y')->count())->toBe(2)
        ->and(app(StateStore::class)->instant('aggregation.last_run_at'))->not->toBeNull();
});

it('backfills in resumable chunks and clears its cursor when done', function () {
    foreach (range(0, 9) as $d) {
        writeEvent('x.y', CarbonImmutable::parse('2026-08-01 10:00', 'UTC')->addDays($d), actor('1'));
    }

    // Record definition hashes first so the backfill is the only source of work.
    app(VitisStudio\Tendril\Product\Aggregation\DefinitionVersions::class)->sync();
    db()->table(Tables::DIRTY_BUCKETS)->delete();

    // Simulate an interrupted run: a saved cursor half-way.
    app(StateStore::class)->put(BackfillCommand::CURSOR, ['next' => '2026-08-06T00:00:00.000000Z', 'to' => '2026-08-10T23:59:59.000000Z']);

    $this->artisan('tendril:backfill', ['--days' => 2])
        ->expectsOutputToContain('Resuming backfill at 2026-08-06')
        ->assertSuccessful();

    expect(db()->table(Tables::ROLLUPS)->where('granularity', 'day')->where('dimension', 'x.y')->count())->toBe(5)
        ->and(app(StateStore::class)->get(BackfillCommand::CURSOR))->toBeNull();

    $this->artisan('tendril:backfill', ['--from' => '2026-08-01', '--to' => '2026-08-10', '--restart' => true])->assertSuccessful();

    expect(db()->table(Tables::ROLLUPS)->where('granularity', 'day')->where('dimension', 'x.y')->count())->toBe(10);
});

it('rebuilds rollups on demand', function () {
    writeEvent('x.y', CarbonImmutable::parse('2026-09-01 10:00', 'UTC'), actor('1'));
    $this->artisan('tendril:aggregate', ['--loop' => true]);
    db()->table(Tables::ROLLUPS)->delete();

    $this->artisan('tendril:rebuild-rollups', ['--run' => true])->assertSuccessful();

    expect(db()->table(Tables::ROLLUPS)->where('dimension', 'x.y')->count())->toBe(2);
});

it('exports and imports feature definitions as JSON', function () {
    app(FeatureRepository::class)->save(FeatureDefinition::fromArray(['key' => 'search', 'definition' => ['events' => ['search.*']]]));
    $path = sys_get_temp_dir().'/tendril-features-'.uniqid().'.json';

    $this->artisan('tendril:features', ['action' => 'export', 'path' => $path])->assertSuccessful();
    app(FeatureRepository::class)->delete('search');
    $this->artisan('tendril:features', ['action' => 'import', 'path' => $path])->expectsOutputToContain('Imported 1')->assertSuccessful();

    expect(app(FeatureRepository::class)->find('search')?->events)->toBe(['search.*']);

    @unlink($path);
});
