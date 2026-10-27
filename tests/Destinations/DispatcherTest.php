<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Destinations\DeliveryResult;

beforeEach(function () {
    config([
        'tendril-destinations.destinations' => [
            'crm' => ['driver' => 'webhook', 'url' => 'https://hooks.example.test/tendril', 'secret' => str_repeat('k', 32), 'conversions' => ['purchase' => 'purchase']],
            'meta' => ['driver' => 'meta', 'pixel_id' => '1', 'access_token' => 'token', 'conversions' => ['purchase' => 'Purchase']],
        ],
    ]);

    $at = CarbonImmutable::now('UTC')->subHours(2);
    $session = (string) Str::ulid();

    app(BatchWriter::class)->write(Batch::make(sessions: [[
        'id' => $session,
        'open' => ['started_at' => Instant::serialize($at), 'click_ids' => ['fbclid' => 'fb-click'], 'environment' => 'testing'],
    ]]));

    $this->withClick = writeEvent('order.completed', $at, actor('1') + ['session_id' => $session, 'properties' => ['revenue' => '10.00', 'currency' => 'USD']]);
    $this->withoutClick = writeEvent('order.completed', $at->addMinute(), actor('2') + ['properties' => ['revenue' => '20.00', 'currency' => 'USD']]);
    writeEvent('order.completed', CarbonImmutable::now('UTC')->subMinute(), actor('3'));
    writeEvent('order.completed', CarbonImmutable::now('UTC')->subDays(10), actor('4'));
});

it('delivers settled conversions once per destination and records outcomes', function () {
    Http::fake([
        'hooks.example.test/*' => Http::response(['ok' => true]),
        'graph.facebook.com/*' => Http::response(['events_received' => 1]),
    ]);

    $this->artisan('tendril:destinations:dispatch')->assertSuccessful();

    $deliveries = db()->table(Tables::DELIVERIES)->get();

    expect($deliveries->where('destination', 'crm')->where('status', DeliveryResult::DELIVERED))->toHaveCount(2)
        ->and($deliveries->where('destination', 'meta')->where('event_id', $this->withClick)->sole()->status)->toBe(DeliveryResult::DELIVERED)
        ->and($deliveries->where('destination', 'meta')->where('event_id', $this->withoutClick)->sole()->status)->toBe(DeliveryResult::SKIPPED)
        // Not yet settled, and outside the lookback window: untouched.
        ->and($deliveries)->toHaveCount(4);

    Http::assertSentCount(2);

    // Idempotent: nothing is re-sent.
    $this->artisan('tendril:destinations:dispatch')->assertSuccessful();
    Http::assertSentCount(2);
});

it('retries failures up to the attempt limit', function () {
    config(['tendril-destinations.max_attempts' => 2, 'tendril-destinations.destinations.meta.enabled' => false]);
    Http::fake(['*' => Http::response('down', 503)]);

    $this->artisan('tendril:destinations:dispatch', ['--destination' => 'crm']);
    $this->artisan('tendril:destinations:dispatch', ['--destination' => 'crm']);
    $this->artisan('tendril:destinations:dispatch', ['--destination' => 'crm']);

    $rows = db()->table(Tables::DELIVERIES)->where('destination', 'crm')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe([DeliveryResult::FAILED])
        ->and($rows->pluck('attempts')->map(fn ($a) => (int) $a)->unique()->all())->toBe([2])
        ->and($rows->first()->response_code)->toEqual(503);

    Http::assertSentCount(2);
});
