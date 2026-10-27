<?php

use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\TenantRef;
use VitisStudio\Tendril\Core\Presence\PresenceManager;
use Workbench\App\Models\User;

it('counts recently active actors per tenant and expires them after the TTL', function () {
    $presence = app(PresenceManager::class);
    $teamA = new TenantRef('App\\Models\\Team', '1');
    $teamB = new TenantRef('App\\Models\\Team', '2');
    $now = time();

    $presence->touch(new ActorRef('App\\Models\\User', '1'), $teamA, $now);
    $presence->touch(new ActorRef('App\\Models\\User', '2'), $teamA, $now);
    $presence->touch(new ActorRef('App\\Models\\User', '3'), $teamB, $now);
    $presence->touch(new ActorRef('App\\Models\\User', '4'), $teamB, $now - 600);

    expect($presence->count($teamA))->toBe(2)
        ->and($presence->count($teamB))->toBe(1)
        ->and($presence->count())->toBe(3)
        ->and(array_map(fn ($m) => $m['actor']->id, $presence->members($teamB)))->toBe(['3']);
});

it('rate-limits refreshes', function () {
    $presence = app(PresenceManager::class);
    $actor = new ActorRef('App\\Models\\User', '9');

    expect($presence->touch($actor))->toBeTrue()
        ->and($presence->touch($actor))->toBeFalse();
});

it('refreshes presence on authenticated activity', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/pricing')->assertOk();

    expect(app(PresenceManager::class)->members()[0]['actor']->id)->toBe((string) $user->id);
});

it('forgets actors on request', function () {
    $presence = app(PresenceManager::class);
    $actor = new ActorRef('App\\Models\\User', '5');
    $presence->touch($actor, null, time());
    $presence->forget($actor);

    expect($presence->count())->toBe(0);
});
