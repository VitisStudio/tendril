<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Privacy\EraseSubject;
use VitisStudio\Tendril\Core\Storage\Audit;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

beforeEach(function () {
    $this->at = CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC');
    $this->victim = new ActorRef('App\\Models\\User', '7');

    foreach (range(0, 6) as $i) {
        writeEvent('profile.viewed', $this->at->addMinutes($i), actor('7', '1') + ['anonymous_id' => 'anon-7', 'properties' => ['email' => 'seven@example.test', 'plan' => 'pro']]);
    }

    writeEvent('page.viewed', $this->at->subHour(), ['anonymous_id' => 'anon-7', 'properties' => ['name' => 'Seven']]);
    writeEvent('profile.viewed', $this->at, actor('8', '1') + ['properties' => ['email' => 'eight@example.test']]);

    app(BatchWriter::class)->write(Batch::make(
        sessions: [['id' => (string) Illuminate\Support\Str::ulid(), 'open' => ['anonymous_id' => 'anon-7', 'actor_type' => 'App\\Models\\User', 'actor_id' => '7', 'started_at' => Instant::serialize($this->at), 'utm_source' => 'google', 'environment' => 'testing']]],
        aliases: [['action' => 'link', 'anonymous_id' => 'anon-7', 'actor_type' => 'App\\Models\\User', 'actor_id' => '7', 'at' => Instant::serialize($this->at), 'reason' => 'login']],
    ));

    aggregate();
});

it('pseudonymizes the actor, strips PII, deletes identity rows and audits', function () {
    $erasureId = Tendril::erase($this->victim, 'dpo@example.test');

    $events = storedEvents();
    $erasure = db()->table(Tables::ERASURES)->find($erasureId);

    expect($events->where('actor_id', '7'))->toHaveCount(0)
        ->and($events->where('anonymous_id', 'anon-7'))->toHaveCount(0)
        ->and($events->where('name', 'profile.viewed')->where('actor_type', 'App\\Models\\User')->pluck('actor_id')->filter(fn ($id) => str_starts_with((string) $id, 'erased:'))->unique())->toHaveCount(1)
        ->and(json_encode($events->all()))->not->toContain('seven@example.test')
        ->and(json_encode($events->all()))->not->toContain('Seven')
        ->and(json_encode($events->all()))->toContain('eight@example.test')
        ->and($events->where('actor_id', '7')->count() + $events->where('actor_id', '8')->count())->toBe(1)
        ->and(db()->table(Tables::ALIASES)->count())->toBe(0)
        ->and(db()->table(Tables::SESSIONS)->count())->toBe(0)
        ->and(db()->table(Tables::TOUCHPOINTS)->count())->toBe(0)
        ->and($erasure->status)->toBe('completed')
        ->and(json_decode($erasure->counts, true)['events_actor'])->toBe(7)
        ->and(db()->table(Tables::AUDIT)->whereIn('action', ['erasure.requested', 'erasure.completed'])->count())->toBe(2)
        ->and(db()->table(Tables::AUDIT)->where('action', 'erasure.requested')->value('performed_by'))->toBe('dpo@example.test')
        ->and(db()->table(Tables::AUDIT)->where('action', 'erasure.requested')->value('subject'))->toContain(Audit::fingerprint($this->victim->key()))
        ->and(db()->table(Tables::AUDIT)->pluck('subject')->implode(''))->not->toContain('"7"');
});

it('removes derived activity immediately and rebuilds retained days without the identity', function () {
    expect(db()->table(Tables::ACTIVITY)->where('actor_id', '7')->count())->toBeGreaterThan(0);

    Tendril::erase($this->victim);

    expect(db()->table(Tables::ACTIVITY)->where('actor_id', '7')->count())->toBe(0)
        ->and(app(DirtyBuckets::class)->count())->toBeGreaterThan(0);

    aggregate();

    expect(db()->table(Tables::ACTIVITY)->where('actor_id', '7')->count())->toBe(0)
        ->and(db()->table(Tables::ACTIVITY)->where('actor_id', '8')->count())->toBeGreaterThan(0)
        ->and(db()->table(Tables::ACTIVITY)->where('actor_type', 'App\\Models\\User')->where('actor_id', 'like', 'erased:%')->where('kind', 'any')->count())->toBe(1);
});

it('is chunked and resumable', function () {
    config(['tendril.privacy.erasure_chunk' => 2]);
    Queue::fake();

    $erasureId = Tendril::erase($this->victim);
    Queue::assertPushed(EraseSubject::class);

    $job = new EraseSubject($erasureId, $this->victim->type, $this->victim->id, null, maxChunks: 1);
    $runs = 0;

    do {
        $job->handle(db(), app(DirtyBuckets::class), app(Audit::class));
        $runs++;
    } while (db()->table(Tables::ERASURES)->where('id', $erasureId)->value('status') !== 'completed' && $runs < 20);

    expect($runs)->toBeGreaterThan(3)
        ->and(storedEvents()->where('actor_id', '7'))->toHaveCount(0);
});
