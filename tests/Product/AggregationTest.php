<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Storage\Pruner;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Aggregation\Aggregator;
use VitisStudio\Tendril\Product\Features\FeatureDefinition;
use VitisStudio\Tendril\Product\Features\FeatureRepository;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

function rollup(string $granularity, string $bucket, string $kind, string $dimension, string $tenant = ''): ?int
{
    $value = db()->table(Tables::ROLLUPS)
        ->where('granularity', $granularity)
        ->where('bucket_start', db()->time(Instant::parse($bucket)))
        ->where('kind', $kind)
        ->where('dimension', $dimension)
        ->where('tenant_id', $tenant)
        ->value('count');

    return $value === null ? null : (int) $value;
}

beforeEach(function () {
    $this->day = CarbonImmutable::parse('2026-09-10 00:00:00', 'UTC');
});

it('rolls up counts per hour and day, per tenant, for events and features', function () {
    writeEvent('report.exported', $this->day->setTime(9, 10), actor('1', '1'));
    writeEvent('report.exported', $this->day->setTime(9, 50), actor('2', '1'));
    writeEvent('report.exported', $this->day->setTime(14, 5), actor('1', '1'));
    writeEvent('report.exported', $this->day->setTime(14, 6), actor('3', '2'));
    writeEvent('page.viewed', $this->day->setTime(14, 7), actor('3', '2') + ['context' => ['route' => 'reports.show']]);

    aggregate();

    expect(rollup('hour', '2026-09-10 09:00', 'event', 'report.exported', '1'))->toBe(2)
        ->and(rollup('hour', '2026-09-10 14:00', 'event', 'report.exported', '1'))->toBe(1)
        ->and(rollup('day', '2026-09-10', 'event', 'report.exported', '1'))->toBe(3)
        ->and(rollup('day', '2026-09-10', 'event', 'report.exported', '2'))->toBe(1)
        // "reporting" (workbench code definition): report.exported + reports.* page views.
        ->and(rollup('day', '2026-09-10', 'feature', 'reporting', '2'))->toBe(2);
});

it('is idempotent and never double counts on re-aggregation', function () {
    writeEvent('x.y', $this->day->setTime(1, 0), actor('1'));
    aggregate();

    app(DirtyBuckets::class)->markRange($this->day, $this->day->addDay());
    aggregate();
    aggregate();

    expect(rollup('day', '2026-09-10', 'event', 'x.y'))->toBe(1)
        ->and(db()->table(Tables::ACTIVITY)->where('kind', 'any')->count())->toBe(1);
});

it('reopens buckets for late-arriving events', function () {
    writeEvent('x.y', $this->day->setTime(1, 0), actor('1'));
    aggregate();
    expect(rollup('day', '2026-09-10', 'event', 'x.y'))->toBe(1);

    // Arrives days later, occurred in the old bucket.
    writeEvent('x.y', $this->day->setTime(1, 30), actor('2'));
    aggregate();

    expect(rollup('day', '2026-09-10', 'event', 'x.y'))->toBe(2)
        ->and(app(ProductMetrics::class)->dau($this->day, TenantScope::all()))->toBe(2);
});

it('never recomputes buckets older than the pruned horizon', function () {
    writeEvent('x.y', $this->day->setTime(1, 0), actor('1'));
    aggregate();

    app(StateStore::class)->putInstant(Pruner::PRUNED_BEFORE, $this->day->addDay());
    db()->table(Tables::EVENTS)->delete();
    app(DirtyBuckets::class)->markRange($this->day, $this->day->addDay());
    $result = aggregate();

    expect($result->skipped)->toBe(24)
        ->and(rollup('day', '2026-09-10', 'event', 'x.y'))->toBe(1)
        ->and(app(DirtyBuckets::class)->count())->toBe(0);
});

it('re-evaluates history when a definition changes', function () {
    writeEvent('onboarding.step', $this->day->setTime(3, 0), actor('1'));
    aggregate();

    expect(rollup('day', '2026-09-10', 'feature', 'onboarding'))->toBeNull();

    app(FeatureRepository::class)->save(FeatureDefinition::fromArray(['key' => 'onboarding', 'definition' => ['events' => ['onboarding.*']]]));
    $result = aggregate();

    expect($result->definitionsChanged)->toContain('features')
        ->and(rollup('day', '2026-09-10', 'feature', 'onboarding'))->toBe(1);
});

it('writes one activity row per actor, day, kind and subject, attributing aliased visitors', function () {
    writeEvent('page.viewed', $this->day->setTime(8, 0), ['anonymous_id' => 'visitor', 'context' => ['route' => 'pricing']]);
    writeEvent('page.viewed', $this->day->setTime(9, 0), ['anonymous_id' => 'stranger', 'context' => ['route' => 'pricing']]);
    writeEvent('report.exported', $this->day->setTime(10, 0), actor('5') + ['anonymous_id' => 'visitor']);
    writeEvent('report.exported', $this->day->setTime(11, 0), actor('5') + ['anonymous_id' => 'visitor']);

    app(BatchWriter::class)->write(Batch::make(aliases: [[
        'action' => 'link', 'anonymous_id' => 'visitor', 'actor_type' => 'App\\Models\\User', 'actor_id' => '5',
        'at' => Instant::serialize($this->day->setTime(10, 0)), 'reason' => 'login',
    ]]));

    aggregate();

    $rows = db()->table(Tables::ACTIVITY)->orderBy('kind')->orderBy('subject')->orderBy('actor_id')->get();
    $user = $rows->where('actor_id', '5');

    expect($user->where('kind', 'any')->sole()->event_count)->toEqual(3)
        ->and($user->where('kind', 'event')->pluck('subject')->sort()->values()->all())->toBe(['page.viewed', 'report.exported'])
        ->and($user->where('kind', 'feature')->pluck('subject')->all())->toBe(['reporting'])
        ->and($rows->where('actor_type', ActorRef::ANONYMOUS)->pluck('actor_id')->unique()->values()->all())->toBe(['stranger'])
        ->and(app(ProductMetrics::class)->dau($this->day, TenantScope::all()))->toBe(1)
        ->and(app(ProductMetrics::class)->activeActors($this->day, $this->day, TenantScope::all(), includeAnonymous: true))->toBe(2);
});

it('recomputes session counters exactly and rolls up sessions and bounces', function () {
    $session = (string) Illuminate\Support\Str::ulid();
    $bounce = (string) Illuminate\Support\Str::ulid();

    app(BatchWriter::class)->write(Batch::make(sessions: [
        ['id' => $session, 'open' => ['started_at' => Instant::serialize($this->day->setTime(9, 0)), 'environment' => 'testing'], 'last_seen_at' => Instant::serialize($this->day->setTime(9, 10))],
        ['id' => $bounce, 'open' => ['started_at' => Instant::serialize($this->day->setTime(12, 0)), 'environment' => 'testing'], 'last_seen_at' => Instant::serialize($this->day->setTime(12, 0))],
    ]));

    writeEvent('page.viewed', $this->day->setTime(9, 0), ['session_id' => $session, 'anonymous_id' => 'a']);
    writeEvent('page.viewed', $this->day->setTime(9, 5), ['session_id' => $session, 'anonymous_id' => 'a']);
    writeEvent('cta.clicked', $this->day->setTime(9, 6), ['session_id' => $session, 'anonymous_id' => 'a']);
    writeEvent('page.viewed', $this->day->setTime(12, 0), ['session_id' => $bounce, 'anonymous_id' => 'b']);

    aggregate();
    aggregate();

    $row = db()->table(Tables::SESSIONS)->find($session);
    $summary = app(ProductMetrics::class)->sessions($this->day, $this->day, TenantScope::all());

    expect((int) $row->event_count)->toBe(3)
        ->and((int) $row->page_view_count)->toBe(2)
        ->and($summary['sessions'])->toBe(2)
        ->and($summary['bounced'])->toBe(1)
        ->and($summary['avg_duration_seconds'])->toBe(300.0);
});

it('skips a run while another process holds the aggregation lock', function () {
    writeEvent('x.y', $this->day->setTime(1, 0), actor('1'));
    $lock = Cache::lock('tendril:aggregate', 30);

    expect($lock->get())->toBeTrue();

    $blocked = app(Aggregator::class)->run();

    expect($blocked->locked)->toBeTrue()
        ->and($blocked->hours)->toBe(0)
        ->and(db()->table(Tables::ROLLUPS)->count())->toBe(0);

    $lock->release();

    expect(app(Aggregator::class)->run()->hours)->toBeGreaterThan(0)
        ->and(db()->table(Tables::ROLLUPS)->count())->toBeGreaterThan(0);
});
