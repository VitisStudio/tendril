<?php

use Illuminate\Support\Str;
use VitisStudio\Tendril\Core\Decisions\Recognizers\DropRoutes;
use VitisStudio\Tendril\Core\Support\Tables;

function beacon(array $events, array $headers = ['HTTP_ORIGIN' => 'http://localhost']): Illuminate\Testing\TestResponse
{
    return test()->call('POST', '/tendril/collect', [], [], [], $headers + ['CONTENT_TYPE' => 'text/plain'], json_encode(['events' => $events]));
}

function beaconEvent(array $overrides = []): array
{
    return array_merge(['id' => (string) Str::ulid(), 'name' => 'page.viewed', 'ts' => (int) (microtime(true) * 1000), 'path' => '/pricing'], $overrides);
}

it('is off by default', function () {
    beacon([beaconEvent()])->assertNotFound();
    $this->get('/tendril/beacon.js')->assertNotFound();
});

describe('when enabled', function () {
    beforeEach(fn () => config(['tendril.beacon.enabled' => true]));

    it('serves a small dependency-free script', function () {
        $response = $this->get('/tendril/beacon.js')->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('javascript')
            ->and(strlen($response->getContent()))->toBeLessThan(6000)
            ->and($response->getContent())->toContain('sendBeacon');
    });

    it('records validated events with server-resolved identity and route', function () {
        $event = beaconEvent();

        beacon([$event])->assertStatus(202);

        $stored = storedEvents('page.viewed')->sole();

        expect($stored->source)->toBe('beacon')
            ->and($stored->context['route'])->toBe('pricing')
            ->and($stored->dedupe_key)->toBe('bc:'.$event['id'])
            ->and($stored->session_id)->not->toBeNull()
            ->and($stored->anonymous_id)->not->toBeNull();
    });

    it('dedupes resends of the same client id', function () {
        $event = beaconEvent();

        beacon([$event])->assertStatus(202);
        beacon([$event])->assertStatus(202);

        expect(storedEvents('page.viewed'))->toHaveCount(1);
    });

    it('rejects foreign origins, oversized, malformed and unknown payloads', function () {
        beacon([beaconEvent()], ['HTTP_ORIGIN' => 'https://evil.example'])->assertForbidden();
        beacon([beaconEvent()], [])->assertForbidden();
        beacon([beaconEvent(['name' => 'admin.deleted'])])->assertStatus(422);
        beacon([beaconEvent(['id' => 'not-a-ulid'])])->assertStatus(422);
        beacon([beaconEvent(['path' => 'https://elsewhere/x'])])->assertStatus(422);
        beacon(array_fill(0, 21, beaconEvent()))->assertStatus(422);
        beacon([beaconEvent(['properties' => ['blob' => str_repeat('x', 20000)]])])->assertStatus(413);

        $this->call('POST', '/tendril/collect', [], [], [], ['HTTP_ORIGIN' => 'http://localhost', 'CONTENT_TYPE' => 'text/plain'], '{broken')->assertStatus(400);

        expect(storedEvents())->toHaveCount(0);
    });

    it('applies the TrackingDecision hook', function () {
        config(['tendril.decisions' => [[DropRoutes::class, ['routes' => ['pricing']]]]]);

        beacon([beaconEvent()])->assertStatus(202);

        expect(storedEvents())->toHaveCount(0);
    });

    it('uses client time only within the drift window', function () {
        beacon([beaconEvent(['ts' => 946684800000])])->assertStatus(202);

        expect(VitisStudio\Tendril\Core\Support\Instant::parse(storedEvents()->sole()->occurred_at)->year)->toBe((int) date('Y'));
    });

    it('captures acquisition from the page the beacon ran on', function () {
        beacon([beaconEvent(['path' => '/', 'utm' => ['utm_source' => 'newsletter', 'utm_medium' => 'email'], 'referrer' => 'https://mail.google.com/'])])->assertStatus(202);

        $session = db()->table(Tables::SESSIONS)->sole();

        expect($session->utm_source)->toBe('newsletter')
            ->and($session->landing_route)->toBe('home')
            ->and(db()->table(Tables::TOUCHPOINTS)->sole()->source)->toBe('newsletter');
    });
});
