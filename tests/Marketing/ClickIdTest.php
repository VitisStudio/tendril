<?php

use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Support\Tables;

it('never stores click IDs unless enabled', function () {
    $this->get('/?gclid=CANARY-GCLID&utm_source=google&utm_medium=cpc');

    expect(db()->table(Tables::SESSIONS)->sole()->click_ids)->toBeNull()
        ->and(json_encode(db()->table(Tables::TOUCHPOINTS)->get()))->not->toContain('CANARY-GCLID');
});

it('stores click IDs only with consent when enabled', function () {
    config(['tendril.acquisition.click_ids.enabled' => true]);

    $this->get('/?gclid=no-consent-gclid');
    expect(db()->table(Tables::SESSIONS)->sole()->click_ids)->toBeNull();

    Tendril::consentUsing(fn () => true);
    $this->get('/?gclid=consented-gclid&fbclid=fb.click_123');

    $session = db()->table(Tables::SESSIONS)->orderByDesc('started_at')->first();
    $touchpoint = db()->table(Tables::TOUCHPOINTS)->where('session_id', $session->id)->sole();

    expect(json_decode($session->click_ids, true))->toEqual(['gclid' => 'consented-gclid', 'fbclid' => 'fb.click_123'])
        ->and((bool) $touchpoint->has_click_id)->toBeTrue()
        ->and($touchpoint->medium)->toBe('cpc');
});

it('rejects malformed click IDs', function () {
    config(['tendril.acquisition.click_ids.enabled' => true, 'tendril.acquisition.click_ids.require_consent' => false]);

    $this->get('/?gclid='.urlencode('<script>alert(1)</script>'));

    expect(db()->table(Tables::SESSIONS)->sole()->click_ids)->toBeNull();
});
