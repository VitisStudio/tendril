<?php

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Contracts\BufferStore;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Storage\IngestionStats;
use VitisStudio\Tendril\Core\Transport\Buffer\ArrayBufferStore;
use VitisStudio\Tendril\Core\Transport\Buffer\RedisBufferStore;
use VitisStudio\Tendril\Core\Transport\BufferDrainer;

beforeEach(function () {
    $this->clock = time();
    $this->store = new ArrayBufferStore;
    $this->store->setClock(fn () => $this->clock);
    app()->instance(BufferStore::class, $this->store);
    config(['tendril.transport.driver' => 'redis-buffer']);
});

it('buffers compact batches and drains them into batch inserts', function () {
    Tendril::track('buffered.one');
    Tendril::track('buffered.two');
    Tendril::flush();
    Tendril::track('buffered.three');
    Tendril::flush();

    expect($this->store->pending())->toBe(2)->and(storedEvents())->toHaveCount(0);

    $this->artisan('tendril:drain')->assertSuccessful();

    expect(storedEvents())->toHaveCount(3)
        ->and($this->store->pending())->toBe(0)
        ->and($this->store->inFlight())->toBe(0);
});

it('recovers batches claimed by a worker that crashed before persisting', function () {
    Tendril::track('crash.safe');
    Tendril::flush();

    // Worker claims, then dies without acknowledging.
    $claimed = $this->store->claim(10);
    expect($claimed)->toHaveCount(1)->and($this->store->inFlight())->toBe(1);

    // Before the timeout nothing is requeued.
    expect(app(BufferDrainer::class)->drain()['persisted'])->toBe(0);

    $this->clock += 301;
    $result = app(BufferDrainer::class)->drain();

    expect($result['requeued'])->toBe(1)
        ->and($result['persisted'])->toBe(1)
        ->and(storedEvents('crash.safe'))->toHaveCount(1);

    // Duplicate delivery of the same payload is idempotent.
    $this->store->push($claimed[0]);
    app(BufferDrainer::class)->drain();

    expect(storedEvents('crash.safe'))->toHaveCount(1);
});

it('applies backpressure and dead-letters undecodable payloads', function () {
    config(['tendril.transport.redis_buffer.max_pending' => 1, 'tendril.debug.throw' => false]);

    Tendril::track('first.batch');
    Tendril::flush();
    Tendril::track('rejected.batch');
    Tendril::flush();

    expect($this->store->pending())->toBe(1)
        ->and(app(IngestionStats::class)->day()['dropped'])->toBeGreaterThanOrEqual(1);

    $this->store->push('{not json');
    $result = app(BufferDrainer::class)->drain();

    expect($result['dead_lettered'])->toBe(1)
        ->and(db()->table('tendril_dead_letters')->count())->toBe(1);
});

it('works against a real Redis server when one is available', function () {
    try {
        app('redis')->connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not reachable.');
    }

    $store = new RedisBufferStore('default', 'tendril:test:'.Str::lower(Str::random(8)));
    app()->instance(BufferStore::class, $store);

    Tendril::track('redis.buffered');
    Tendril::flush();

    expect($store->pending())->toBe(1);

    $claimed = $store->claim(1);
    expect($store->inFlight())->toBe(1)->and($store->requeueStale(0))->toBe(1);

    app(BufferDrainer::class)->drain();

    expect(storedEvents('redis.buffered'))->toHaveCount(1)
        ->and($store->pending())->toBe(0)
        ->and($store->inFlight())->toBe(0)
        ->and($claimed)->toHaveCount(1);
});
