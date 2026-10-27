<?php

use Illuminate\Support\Facades\Schema;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Core\Testing\SyntheticDataset;

beforeEach(fn () => $this->freshSchema());

it('never drops or renames a column shipped in a previous version (schema snapshot)', function () {
    $snapshot = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/schema-1.0.json'), true);

    foreach ($snapshot['tables'] as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} disappeared");

        $current = Schema::getColumnListing($table);

        foreach ($columns as $column) {
            expect($current)->toContain($column);
        }
    }
});

it('upgrades a populated events table online without rewriting it', function () {
    (new SyntheticDataset(['tenants' => 2, 'actors_per_tenant' => 10, 'visitors_per_day' => 5, 'days' => 30, 'activity' => 0.8]))->generate();

    $before = db()->table(Tables::EVENTS)->count();
    $checksum = db()->table(Tables::EVENTS)->orderBy('id')->limit(50)->pluck('id')->implode(',');
    $started = microtime(true);

    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../Fixtures/migrations'), '--realpath' => true, '--force' => true])->assertSuccessful();

    $seconds = microtime(true) - $started;

    expect(db()->table(Tables::EVENTS)->count())->toBe($before)
        ->and(db()->table(Tables::EVENTS)->orderBy('id')->limit(50)->pluck('id')->implode(','))->toBe($checksum)
        ->and($before)->toBeGreaterThan(1000)
        ->and($seconds)->toBeLessThan(60.0);

    $this->artisan('migrate:rollback', ['--path' => realpath(__DIR__.'/../Fixtures/migrations'), '--realpath' => true, '--force' => true])->assertSuccessful();

    expect(db()->table(Tables::EVENTS)->count())->toBe($before);
});

it('treats derived tables as disposable: drop, re-migrate and rebuild from facts', function () {
    (new SyntheticDataset(['tenants' => 1, 'actors_per_tenant' => 5, 'visitors_per_day' => 2, 'days' => 5]))->generate();
    aggregate();

    $before = db()->table(Tables::ACTIVITY)->orderBy('date')->orderBy('actor_id')->orderBy('kind')->orderBy('subject')->get(['date', 'actor_id', 'kind', 'subject', 'event_count'])->map(fn ($r) => (array) $r)->all();
    $events = db()->table(Tables::EVENTS)->count();
    $path = realpath(__DIR__.'/../../packages/product/database/migrations/2026_09_30_000100_create_tendril_rollup_tables.php');

    $this->artisan('migrate:rollback', ['--path' => $path, '--realpath' => true, '--force' => true])->assertSuccessful();
    expect(Schema::hasTable(Tables::ROLLUPS))->toBeFalse();

    $this->artisan('migrate', ['--path' => $path, '--realpath' => true, '--force' => true])->assertSuccessful();
    $this->artisan('tendril:rebuild-rollups', ['--run' => true])->assertSuccessful();

    $after = db()->table(Tables::ACTIVITY)->orderBy('date')->orderBy('actor_id')->orderBy('kind')->orderBy('subject')->get(['date', 'actor_id', 'kind', 'subject', 'event_count'])->map(fn ($r) => (array) $r)->all();

    expect(db()->table(Tables::EVENTS)->count())->toBe($events)
        ->and($after)->toEqual($before);
});

it('supports a separate analytics connection', function () {
    onlyOn('sqlite');

    config(['database.connections.analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $this->freshSchema(['tendril.database.connection' => 'analytics']);

    VitisStudio\Tendril\Core\Facades\Tendril::track('separate.store');
    VitisStudio\Tendril\Core\Facades\Tendril::flush();

    expect(Schema::connection('analytics')->hasTable(Tables::EVENTS))->toBeTrue()
        ->and(Illuminate\Support\Facades\DB::connection('analytics')->table(Tables::EVENTS)->count())->toBe(1);
});
