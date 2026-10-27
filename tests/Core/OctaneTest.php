<?php

use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use Workbench\App\Models\User;

/**
 * Octane keeps the application in memory between requests. Simulates two
 * sequential requests from different users on one worker (the same app
 * instance), with Octane's lifecycle events around each.
 */
it('isolates buffer and context between sequential requests from different users', function () {
    [$alice, $bob] = User::factory()->count(2)->create();

    event(new RequestReceived(app(), app(), request()));
    $this->actingAs($alice)->get('/pricing')->assertOk();
    Tendril::track('outside.middleware');
    event(new RequestTerminated(app(), app(), request(), response('')));

    // Anything that survived the request must be gone before the next one.
    expect(app(ContextStore::class)->snapshot())->toBe([])
        ->and(app(Buffer::class)->count())->toBe(0);

    event(new RequestReceived(app(), app(), request()));
    $this->actingAs($bob)->get('/pricing')->assertOk();
    event(new RequestTerminated(app(), app(), request(), response('')));

    $views = storedEvents('page.viewed');

    expect($views)->toHaveCount(2)
        ->and($views[0]->actor_id)->toBe((string) $alice->id)
        ->and($views[1]->actor_id)->toBe((string) $bob->id)
        ->and($views[0]->request_id)->not->toBe($views[1]->request_id)
        ->and(storedEvents('outside.middleware')->sole()->actor_id)->toBe((string) $alice->id);
});

it('drops leaked state when a new request starts', function () {
    $store = app(ContextStore::class);
    $store->begin('http');
    $store->setActor(new ActorRef('App\\Models\\User', 'leaked'));
    Tendril::track('should.not.leak');

    event(new RequestReceived(app(), app(), request()));

    expect($store->snapshot())->toBe([])
        ->and(app(Buffer::class)->count())->toBe(0);
});
