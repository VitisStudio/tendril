<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use VitisStudio\Tendril\Destinations\Adapters\GoogleAdsDestination;
use VitisStudio\Tendril\Destinations\Adapters\MetaConversionsApiDestination;
use VitisStudio\Tendril\Destinations\Adapters\WebhookDestination;
use VitisStudio\Tendril\Destinations\DeliveryResult;
use VitisStudio\Tendril\Destinations\OutboundConversion;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRecord;

function outbound(string $eventId = '01JA0000000000000000000001', array $clickIds = [], ?int $value = 4999, ?string $currency = 'USD', string $event = 'Purchase'): OutboundConversion
{
    return new OutboundConversion(
        new ConversionRecord('purchase', $eventId, CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'), null, 'anon', null, null, null, $value, $currency),
        $event,
        $clickIds,
    );
}

it('formats major values exactly from minor units', function () {
    expect(outbound(value: 4999)->majorValue())->toBe('49.99')
        ->and(outbound(value: 5)->majorValue())->toBe('0.05')
        ->and(outbound(value: -1250)->majorValue())->toBe('-12.50')
        ->and(outbound(value: 1500, currency: 'JPY')->majorValue())->toBe('1500')
        ->and(outbound(value: null)->majorValue())->toBeNull();
});

it('signs webhook deliveries and never sends identity', function () {
    Http::fake(['hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $secret = str_repeat('s', 32);
    $destination = new WebhookDestination('crm', ['url' => 'https://hooks.example.test/tendril', 'secret' => $secret]);

    $results = $destination->send([outbound(clickIds: ['gclid' => 'hidden-by-default'])]);

    expect($results[0]->status)->toBe(DeliveryResult::DELIVERED);

    Http::assertSent(function (Request $request) use ($secret) {
        $body = $request->body();

        return WebhookDestination::verify($request->header('X-Tendril-Signature')[0], $body, $secret)
            && ! str_contains($body, 'hidden-by-default')
            && ! str_contains($body, 'anon')
            && $request['conversions'][0]['value'] === '49.99'
            && $request['conversions'][0]['event_id'] === '01JA0000000000000000000001';
    });

    expect(WebhookDestination::verify('t=1,v1='.str_repeat('0', 64), '{}', $secret))->toBeFalse();
});

it('reports webhook failures per conversion', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $results = (new WebhookDestination('crm', ['url' => 'https://hooks.example.test/x', 'secret' => str_repeat('s', 32)]))->send([outbound()]);

    expect($results[0]->status)->toBe(DeliveryResult::FAILED)->and($results[0]->responseCode)->toBe(500);
});

it('refuses to build destinations without credentials', function () {
    new MetaConversionsApiDestination('meta', ['pixel_id' => '123']);
})->throws(InvalidArgumentException::class);

it('sends Meta Conversions API events with dedup ids and fbc', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'x'], 200)]);
    $meta = new MetaConversionsApiDestination('meta', ['pixel_id' => '123', 'access_token' => 'test-token', 'api_version' => 'v21.0', 'test_event_code' => 'TEST1']);

    expect($meta->accepts(outbound()))->toBeString();

    $conversion = outbound(clickIds: ['fbclid' => 'abc.def']);
    $results = $meta->send([$conversion]);

    expect($results[0]->status)->toBe(DeliveryResult::DELIVERED);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v21.0/123/events'
        && $r['data'][0]['event_name'] === 'Purchase'
        && $r['data'][0]['event_id'] === '01JA0000000000000000000001'
        && $r['data'][0]['user_data']['fbc'] === 'fb.1.1788264000000.abc.def'
        && $r['data'][0]['custom_data'] === ['value' => 49.99, 'currency' => 'USD']
        && $r['test_event_code'] === 'TEST1');
});

it('uploads Google Ads click conversions with OAuth and partial failures', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'googleads.googleapis.com/*' => Http::response([
            'partialFailureError' => [
                'message' => 'Some conversions failed',
                'details' => [['errors' => [[
                    'message' => 'The click was not found.',
                    'location' => ['fieldPathElements' => [['fieldName' => 'conversions', 'index' => 1]]],
                ]]]],
            ],
            'results' => [['gclid' => 'g1'], []],
        ]),
    ]);

    $ads = new GoogleAdsDestination('google_ads', [
        'customer_id' => '123-456-7890', 'developer_token' => 'dev', 'client_id' => 'cid', 'client_secret' => 'cs',
        'refresh_token' => 'rt', 'login_customer_id' => '999-000-1111', 'api_version' => 'v21',
    ]);

    $action = 'customers/1234567890/conversionActions/42';
    expect($ads->accepts(outbound(event: $action)))->toBeString();

    $results = $ads->send([
        outbound('01JA0000000000000000000001', ['gclid' => 'g1'], event: $action),
        outbound('01JA0000000000000000000002', ['gclid' => 'g2'], event: $action),
    ]);

    expect($results[0]->status)->toBe(DeliveryResult::DELIVERED)
        ->and($results[1]->status)->toBe(DeliveryResult::FAILED)
        ->and($results[1]->error)->toBe('The click was not found.');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://googleads.googleapis.com/v21/customers/1234567890:uploadClickConversions'
        && $r->hasHeader('Authorization', 'Bearer ya29.test')
        && $r->hasHeader('developer-token', 'dev')
        && $r->hasHeader('login-customer-id', '9990001111')
        && $r['partialFailure'] === true
        && $r['conversions'][0]['orderId'] === '01JA0000000000000000000001'
        && $r['conversions'][0]['conversionDateTime'] === '2026-09-01 12:00:00+00:00'
        && $r['conversions'][0]['conversionValue'] === 49.99);
});
