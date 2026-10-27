<?php

use Illuminate\Support\Facades\Schema;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Support\Tables;
use Workbench\App\Models\User;

/**
 * Every Tendril table, serialized, must never contain any canary.
 */
function tendrilDump(): string
{
    $dump = '';

    foreach ([...Tables::core(), Tables::ROLLUPS, Tables::ACTIVITY, Tables::FEATURES, Tables::FUNNELS, Tables::COHORTS, Tables::CONVERSIONS, Tables::DELIVERIES] as $table) {
        if (Schema::hasTable($table)) {
            $dump .= json_encode(db()->table($table)->get());
        }
    }

    return $dump;
}

it('never stores passwords, auth headers, CSRF tokens, cookies, form payloads or IPs', function () {
    $user = User::factory()->create(['email' => 'canary-email@example.test']);

    carry($this->get('/'));
    carry($this->post("/demo/login/{$user->id}", ['password' => 'CANARY-PASSWORD-1']));

    $this->withHeaders([
        'Authorization' => 'Bearer CANARY-BEARER-2',
        'X-Api-Key' => 'CANARY-APIKEY-3',
        'Cookie' => 'secret=CANARY-COOKIE-4',
        'X-Forwarded-For' => '203.0.113.77',
    ])->post('/demo/checkout', [
        '_token' => 'CANARY-CSRF-5',
        'password' => 'CANARY-PASSWORD-6',
        'card_number' => 'CANARY-CARD-7',
        'address' => 'CANARY-ADDRESS-8',
    ])->assertOk();

    Tendril::track('profile.updated', ['api_token' => 'CANARY-TOKEN-9', 'nested' => ['secret' => 'CANARY-SECRET-10'], 'plan' => 'pro']);
    Tendril::flush();
    aggregate();

    $dump = tendrilDump();

    foreach (['CANARY-PASSWORD-1', 'CANARY-BEARER-2', 'CANARY-APIKEY-3', 'CANARY-COOKIE-4', 'CANARY-CSRF-5', 'CANARY-PASSWORD-6', 'CANARY-CARD-7', 'CANARY-ADDRESS-8', 'CANARY-TOKEN-9', 'CANARY-SECRET-10', 'canary-email@example.test', '203.0.113.77', '127.0.0.1'] as $canary) {
        expect($dump)->not->toContain($canary);
    }

    expect(storedEvents('profile.updated')->sole()->properties)->toEqual(['plan' => 'pro']);
});

it('stores only a truncated or hashed IP when explicitly opted in', function () {
    config(['tendril.privacy.ip' => 'truncated']);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->get('/');

    config(['tendril.privacy.ip' => 'hashed']);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->get('/pricing');

    $events = storedEvents('page.viewed');

    expect($events[0]->context['ip'])->toBe('198.51.100.0')
        ->and($events[1]->context['ip'])->toHaveLength(16)
        ->and(tendrilDump())->not->toContain('198.51.100.77');
});
