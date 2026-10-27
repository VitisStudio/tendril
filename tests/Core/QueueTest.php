<?php

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Transport\Jobs\PersistBatch;
use Workbench\App\Jobs\TrackFromJob;
use Workbench\App\Models\User;

it('dispatches exactly one job per request carrying all of its events', function () {
    config(['tendril.transport.driver' => 'queue']);
    Queue::fake();

    $this->post('/demo/checkout')->assertOk();

    Queue::assertPushed(PersistBatch::class, 1);
    Queue::assertPushed(PersistBatch::class, function (PersistBatch $job) {
        $names = array_column($job->batch['events'], 'name');

        return in_array('order.completed', $names, true) && count($job->batch['sessions']) === 1;
    });
});

it('persists through the queue and survives duplicate delivery', function () {
    config(['tendril.transport.driver' => 'queue']);

    Tendril::track('queued.fact', ['n' => 1]);
    $batch = app(Buffer::class)->drain();

    (new PersistBatch($batch->toArray()))->handle(app(VitisStudio\Tendril\Core\Storage\BatchWriter::class));
    (new PersistBatch($batch->toArray()))->handle(app(VitisStudio\Tendril\Core\Storage\BatchWriter::class));

    expect(storedEvents('queued.fact'))->toHaveCount(1);
});

it('propagates request context and the dispatching actor into queued jobs', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/demo/dispatch')->assertOk();

    $request = storedEvents('checkout.started')->sole();
    $job = storedEvents('job.ran')->sole();

    expect($job->request_id)->toBe($request->request_id)
        ->and($job->session_id)->toBe($request->session_id)
        ->and($job->actor_id)->toBe((string) $user->id);
});

it('propagates context through chains and batches', function () {
    $store = app(ContextStore::class);
    $requestId = $store->begin('http');
    $store->setActor(new ActorRef('App\\Models\\User', '42'));

    Bus::chain([new TrackFromJob('chain.first'), new TrackFromJob('chain.second')])->dispatch();
    Bus::batch([new TrackFromJob('batch.one'), new TrackFromJob('batch.two')])->dispatch();
    Tendril::flush();

    $events = storedEvents()->whereIn('name', ['chain.first', 'chain.second', 'batch.one', 'batch.two']);

    expect($events)->toHaveCount(4)
        ->and($events->pluck('request_id')->unique()->all())->toBe([$requestId])
        ->and($events->pluck('actor_id')->unique()->all())->toBe(['42']);
});

it('clears context and buffer between jobs in long-lived workers', function () {
    $store = app(ContextStore::class);
    $store->begin('queue');
    $store->setActor(new ActorRef('App\\Models\\User', 'previous-user'));
    Tendril::track('first.job.fact');

    // A worker (non-sync connection) finishing a job flushes and resets.
    $job = new SyncJob(app(), json_encode(['job' => 'x', 'data' => []]), 'redis', 'default');
    event(new JobProcessed('redis', $job));

    expect(app(ContextStore::class)->snapshot())->toBe([])
        ->and(Context::getHidden(ContextStore::ACTOR))->toBeNull()
        ->and(storedEvents('first.job.fact'))->toHaveCount(1);

    Tendril::track('second.job.fact');
    Tendril::flush();

    expect(storedEvents('second.job.fact')->sole()->actor_id)->toBeNull();
});
