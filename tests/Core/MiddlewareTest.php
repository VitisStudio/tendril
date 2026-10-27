<?php

use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Support\Tables;
use Workbench\App\Models\User;

it('records page views by route name after the response', function () {
    $this->get('/')->assertOk();

    $event = storedEvents('page.viewed')->sole();

    expect($event->context)->toEqual(['route' => 'home', 'navigation' => 'full'])
        ->and($event->properties)->toEqual(['status' => 200])
        ->and($event->source)->toBe('http')
        ->and($event->environment)->toBe('testing')
        ->and($event->dedupe_key)->toBe('pv:'.$event->request_id)
        ->and($event->session_id)->not->toBeNull()
        ->and($event->anonymous_id)->not->toBeNull();
});

it('falls back to the URI pattern, never the resolved path', function () {
    $this->get('/docs/secret-customer-123')->assertOk();

    expect(storedEvents('page.viewed')->sole()->context['route'])->toBe('/docs/{page}')
        ->and(json_encode(storedEvents()->all()))->not->toContain('secret-customer-123');
});

it('skips redirects, JSON, excluded paths, prefetches and Livewire updates', function () {
    $this->get('/go')->assertRedirect();
    $this->get('/api-ish')->assertOk();
    $this->get('/up')->assertOk();
    $this->get('/pricing', ['Sec-Purpose' => 'prefetch'])->assertOk();
    $this->get('/pricing', ['X-Livewire' => '1'])->assertOk();

    expect(storedEvents('page.viewed'))->toHaveCount(0);
});

it('counts wire:navigate fetches as page views', function () {
    $this->get('/pricing', ['X-Livewire-Navigate' => '1'])->assertOk();

    expect(storedEvents('page.viewed')->sole()->context['navigation'])->toBe('navigate');
});

it('keeps one analytics session across requests and splits on inactivity', function () {
    carry($this->get('/'));
    carry($this->get('/pricing'));

    expect(sessions())->toHaveCount(1)
        ->and(storedEvents('page.viewed')->pluck('session_id')->unique())->toHaveCount(1);

    $this->travel(31)->minutes();
    carry($this->get('/pricing'));

    expect(sessions())->toHaveCount(2);
});

it('enforces the hard cap even for continuous activity', function () {
    config(['tendril.sessions.inactivity_minutes' => 30, 'tendril.sessions.hard_cap_minutes' => 60]);

    foreach ([0, 25, 25, 25] as $minutes) {
        $this->travel($minutes)->minutes();
        carry($this->get('/'));
    }

    expect(sessions())->toHaveCount(2);
});

it('starts a new session and touchpoint on new campaign parameters', function () {
    carry($this->get('/?utm_source=Google&utm_medium=CPC&utm_campaign=Brand'));
    carry($this->get('/pricing?utm_source=Google&utm_medium=CPC&utm_campaign=Brand'));
    carry($this->get('/?utm_source=newsletter&utm_medium=email&utm_campaign=sept'));

    $sessions = sessions();
    $touchpoints = db()->table(Tables::TOUCHPOINTS)->orderBy('occurred_at')->get();

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]->utm_source)->toBe('google')
        ->and($sessions[0]->utm_medium)->toBe('cpc')
        ->and($sessions[0]->utm_campaign)->toBe('Brand')
        ->and($sessions[0]->landing_route)->toBe('home')
        ->and($touchpoints)->toHaveCount(2)
        ->and($touchpoints->pluck('source')->all())->toBe(['google', 'newsletter'])
        ->and($touchpoints->pluck('session_id')->all())->toBe($sessions->pluck('id')->all());
});

it('captures external referrers as host and class only', function () {
    carry($this->get('/', ['Referer' => 'https://www.google.com/search?q=private+query']));
    $this->get('/pricing', ['Referer' => 'http://localhost/']);

    $session = sessions()->sole();
    $touchpoint = db()->table(Tables::TOUCHPOINTS)->sole();

    expect($session->referrer_host)->toBe('google.com')
        ->and($session->referrer_class)->toBe('search')
        ->and($touchpoint->source)->toBe('google.com')
        ->and($touchpoint->medium)->toBe('organic')
        ->and(json_encode(db()->table(Tables::SESSIONS)->get()))->not->toContain('private+query');
});

it('stays cookieless by default and survives session regeneration on login and logout', function () {
    $user = User::factory()->create();

    $first = $this->get('/');
    expect($first->getCookie('tendril_aid'))->toBeNull();
    carry($first);

    carry($this->post("/demo/login/{$user->id}"));
    carry($this->get('/pricing'));
    carry($this->post('/demo/logout'));
    carry($this->get('/'));

    expect(sessions())->toHaveCount(1)
        ->and(storedEvents()->pluck('session_id')->filter()->unique())->toHaveCount(1)
        ->and(storedEvents()->pluck('anonymous_id')->filter()->unique())->toHaveCount(1)
        ->and(sessions()->sole()->actor_id)->toBe((string) $user->id);
});

it('sets a persistent visitor cookie only with consent, and forgets it on revocation', function () {
    config(['tendril.identity.mode' => 'cookie']);
    $consent = true;
    Tendril::consentUsing(function () use (&$consent) {
        return $consent;
    });

    $granted = $this->get('/');
    $cookie = $granted->getCookie('tendril_aid', decrypt: true);

    expect($cookie)->not->toBeNull()
        ->and(storedEvents('page.viewed')->sole()->anonymous_id)->toBe($cookie->getValue());

    // Returning visitor in a brand new Laravel session keeps the same anonymous id.
    $this->withCookie('tendril_aid', $cookie->getValue())->get('/pricing');
    expect(storedEvents('page.viewed')->pluck('anonymous_id')->unique())->toHaveCount(1);

    $consent = false;
    $revoked = $this->withCookie('tendril_aid', $cookie->getValue())->get('/');
    $forgotten = $revoked->getCookie('tendril_aid', decrypt: false);

    expect($forgotten)->not->toBeNull()
        ->and($forgotten->getExpiresTime())->toBeLessThan(time())
        ->and(storedEvents('page.viewed')->last()->anonymous_id)->not->toBe($cookie->getValue());
});

it('shares one request id between the page view and explicit facts', function () {
    $this->post('/demo/checkout', ['password' => 'x', 'card_number' => '4242'])->assertOk();

    $order = storedEvents('order.completed')->sole();

    expect($order->request_id)->not->toBeNull()
        ->and($order->session_id)->not->toBeNull()
        ->and($order->source)->toBe('http');
});

it('adopts W3C trace ids and never leaks context between requests', function () {
    $this->get('/', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
    $this->get('/pricing');

    $events = storedEvents('page.viewed');

    expect($events[0]->trace_id)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($events[1]->trace_id)->toBeNull()
        ->and($events[0]->request_id)->not->toBe($events[1]->request_id)
        ->and(Tendril::requestId())->toBeNull();
});

it('honours custom acquisition parameter names', function () {
    config(['tendril.acquisition.utm' => ['source' => 'src', 'medium' => 'med', 'campaign' => 'cmp']]);

    carry($this->get('/?src=Partner&med=referral&cmp=launch&utm_source=ignored'));

    $session = sessions()->sole();

    expect($session->utm_source)->toBe('partner')
        ->and($session->utm_medium)->toBe('referral')
        ->and($session->utm_campaign)->toBe('launch');
});
