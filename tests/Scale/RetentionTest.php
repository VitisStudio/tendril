<?php

use Illuminate\Support\Facades\Storage;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Storage\Archiver;
use VitisStudio\Tendril\Core\Storage\Pruner;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

beforeEach(function () {
    Storage::fake('local');
    config(['tendril.retention.raw_days' => 30, 'tendril.retention.archive.enabled' => true, 'tendril.retention.archive.disk' => 'local']);

    $this->now = Instant::day(Instant::now());
    $this->old = $this->now->subDays(45)->setTime(10, 0);

    writeEvent('old.fact', $this->old, actor('1'));
    writeEvent('old.fact', $this->old->addHour(), actor('2'));
    writeEvent('fresh.fact', $this->now->subDays(2)->setTime(10, 0), actor('1'));
    aggregate();
});

it('archives, then prunes raw events past retention while keeping derived data', function () {
    $dauBefore = app(ProductMetrics::class)->dau($this->old, TenantScope::all());

    $this->artisan('tendril:prune')->assertSuccessful();

    $path = app(Archiver::class)->path(Instant::day($this->old));
    $lines = array_values(array_filter(explode("\n", (string) gzdecode(Storage::disk('local')->get($path)))));

    expect(storedEvents()->pluck('name')->all())->toBe(['fresh.fact'])
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true)['name'])->toBe('old.fact')
        ->and(app(ProductMetrics::class)->dau($this->old, TenantScope::all()))->toBe($dauBefore)
        ->and($dauBefore)->toBe(2)
        ->and(app(StateStore::class)->instant(Pruner::PRUNED_BEFORE)?->toDateString())->toBe($this->now->subDays(30)->toDateString());
});

it('is resumable and never lets aggregation recompute pruned days', function () {
    $this->artisan('tendril:prune')->assertSuccessful();
    $this->artisan('tendril:prune')->assertSuccessful();

    // A late mark for a pruned day (e.g. from an erasure) must not wipe derived data.
    app(VitisStudio\Tendril\Core\Storage\DirtyBuckets::class)->mark([$this->old]);
    aggregate();

    expect(app(ProductMetrics::class)->dau($this->old, TenantScope::all()))->toBe(2);
});

it('supports dry runs and explicit forever retention', function () {
    $this->artisan('tendril:prune', ['--dry-run' => true])->expectsOutputToContain('would be removed')->assertSuccessful();
    expect(storedEvents())->toHaveCount(3);

    config(['tendril.retention.raw_days' => null]);
    $this->artisan('tendril:prune')->expectsOutputToContain('unlimited')->assertSuccessful();
    expect(storedEvents())->toHaveCount(3);
});
