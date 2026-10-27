<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Storage\RowInserter;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

function eventRow(string $name, CarbonImmutable $at, array $overrides = []): array
{
    $id = (string) Str::ulid($at);

    return array_merge([
        'id' => $id, 'occurred_at' => Instant::serialize($at), 'received_at' => Instant::serialize($at),
        'name' => $name, 'version' => 1, 'dedupe_key' => $id, 'source' => 'app', 'environment' => 'testing',
        'properties' => ['n' => 1], 'context' => [],
    ], $overrides);
}

it('stores UTC instants with microsecond precision on every driver', function () {
    $at = CarbonImmutable::parse('2026-09-29 14:20:12.123456', 'UTC');
    writeEvent('precision.check', $at);

    $stored = Instant::parse(storedEvents()->first()->occurred_at);

    expect($stored->format('Y-m-d H:i:s.u'))->toBe('2026-09-29 14:20:12.123456')
        ->and($stored->getTimezone()->getName())->toBe('UTC');
});

it('inserts batches idempotently on the dedupe key', function () {
    $at = CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC');
    $events = [eventRow('a.b', $at), eventRow('a.b', $at->addSecond()), eventRow('a.c', $at->addSeconds(2))];
    $batch = Batch::make($events);

    $first = app(BatchWriter::class)->write($batch);
    $retry = app(BatchWriter::class)->write($batch);

    // Same dedupe key, different id (e.g. a client beacon resend): still one row.
    $resend = eventRow('a.b', $at->addSecond(), ['dedupe_key' => $events[1]['dedupe_key']]);
    $third = app(BatchWriter::class)->write(Batch::make([$resend]));

    expect($first->inserted)->toBe(3)
        ->and($retry->inserted)->toBe(0)
        ->and($retry->duplicates)->toBe(3)
        ->and($third->inserted)->toBe(0)
        ->and(db()->table(Tables::EVENTS)->count())->toBe(3);
});

it('marks the hour buckets of every write dirty, including late events', function () {
    writeEvent('x.y', CarbonImmutable::parse('2026-09-29 10:15:00', 'UTC'));
    writeEvent('x.y', CarbonImmutable::parse('2026-09-29 10:45:00', 'UTC'));
    writeEvent('x.y', CarbonImmutable::parse('2026-01-01 03:30:00', 'UTC'));

    $buckets = collect(app(DirtyBuckets::class)->claim(10))->map(fn ($m) => $m['bucket_start']->toDateTimeString())->all();

    expect($buckets)->toBe(['2026-01-01 03:00:00', '2026-09-29 10:00:00']);
});

it('completes a dirty mark only if it was not re-marked meanwhile', function () {
    $dirty = app(DirtyBuckets::class);
    $hour = CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC');
    $dirty->mark([$hour]);
    [$claimed] = $dirty->claim(1);

    $this->travel(2)->seconds();
    $dirty->mark([$hour]);
    $dirty->complete($claimed);

    expect($dirty->count())->toBe(1);

    [$again] = $dirty->claim(1);
    $dirty->complete($again);

    expect($dirty->count())->toBe(0);
});

it('upserts sessions monotonically and never lets last_seen go backwards', function () {
    $id = (string) Str::ulid();
    $start = CarbonImmutable::parse('2026-09-29 09:00:00', 'UTC');
    $writer = app(BatchWriter::class);

    $writer->write(Batch::make([], [[
        'id' => $id,
        'open' => ['anonymous_id' => 'anon-1', 'started_at' => Instant::serialize($start), 'landing_route' => null, 'utm_source' => 'google', 'environment' => 'testing'],
        'last_seen_at' => Instant::serialize($start->addMinutes(10)),
    ]]));

    $writer->write(Batch::make([], [['id' => $id, 'last_seen_at' => Instant::serialize($start->addMinutes(5)), 'actor_type' => 'App\\Models\\User', 'actor_id' => '9', 'landing_route' => 'pricing']]));

    $session = db()->table(Tables::SESSIONS)->find($id);

    expect(Instant::parse($session->last_seen_at)->toDateTimeString())->toBe('2026-09-29 09:10:00')
        ->and($session->actor_id)->toBe('9')
        ->and($session->landing_route)->toBe('pricing')
        ->and($session->utm_source)->toBe('google');
});

it('isolates poison rows in a failing batch and dead-letters only them', function () {
    $at = CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC');
    $events = [];

    for ($i = 0; $i < 7; $i++) {
        $events[] = eventRow('bulk.event', $at->addSeconds($i), $i === 4 ? ['properties' => ['poison' => true]] : []);
    }

    // Simulated row-level failure (portable across drivers): any chunk containing the poison row fails.
    app()->bind(RowInserter::class, fn () => new class(db()) extends RowInserter
    {
        public function insert(array $rows): int
        {
            foreach ($rows as $row) {
                if (str_contains((string) $row['properties'], 'poison')) {
                    throw new QueryException('testing', 'insert', [], new RuntimeException('value too long for type'));
                }
            }

            return parent::insert($rows);
        }
    });
    app()->forgetInstance(BatchWriter::class);

    $result = app(BatchWriter::class)->write(Batch::make($events));

    expect($result->inserted)->toBe(6)
        ->and($result->failed)->toBe(1)
        ->and(db()->table(Tables::EVENTS)->count())->toBe(6)
        ->and(db()->table(Tables::DEAD_LETTERS)->count())->toBe(1);

    $letter = db()->table(Tables::DEAD_LETTERS)->first();
    expect($letter->reason)->toContain('insert_failed')->and($letter->events)->toEqual(1);
});

it('retries dead letters once the cause is gone', function () {
    $at = CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC');
    app(VitisStudio\Tendril\Core\Storage\DeadLetters::class)->storeRows('batch', [eventRow('late.retry', $at)], 'insert_failed: simulated');

    $this->artisan('tendril:dead-letters', ['--retry' => true])->assertSuccessful();

    expect(storedEvents('late.retry'))->toHaveCount(1)
        ->and(db()->table(Tables::DEAD_LETTERS)->count())->toBe(0);
});
