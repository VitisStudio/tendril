<?php

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Support\Tables;
use Workbench\App\Models\User;

it('maps login, registration and logout to facts with actors and reasons', function () {
    $user = User::factory()->create();

    carry($this->get('/'));
    carry($this->post("/demo/login/{$user->id}"));
    carry($this->post('/demo/logout'));
    $this->post('/demo/register');

    $login = storedEvents('auth.login')->all();
    $logout = storedEvents('auth.logout')->sole();
    $registered = storedEvents('auth.registered')->sole();

    expect($login)->toHaveCount(2)
        ->and($login[0]->actor_id)->toBe((string) $user->id)
        ->and($login[0]->properties)->toEqual(['guard' => 'web', 'remember' => false])
        ->and($logout->actor_id)->toBe((string) $user->id)
        ->and($logout->properties)->toEqual(['guard' => 'web', 'reason' => 'user'])
        ->and($registered->actor_id)->not->toBe((string) $user->id);
});

it('never records Authenticated and ignores remember-me re-logins by default', function () {
    $user = User::factory()->create();

    event(new Authenticated('web', $user));

    Auth::extend('remembering', fn () => new class implements Guard
    {
        use GuardHelpers;

        public function user()
        {
            return $this->user;
        }

        public function validate(array $credentials = [])
        {
            return false;
        }

        public function viaRemember(): bool
        {
            return true;
        }
    });
    config(['auth.guards.remembering' => ['driver' => 'remembering', 'provider' => 'users']]);

    event(new Login('remembering', $user, true));
    Tendril::flush();

    expect(storedEvents())->toHaveCount(0);

    config(['tendril.auth.track_remember_logins' => true]);
    event(new Login('remembering', $user, true));
    Tendril::flush();

    expect(storedEvents('auth.login'))->toHaveCount(1);
});

it('maps device logouts with their reasons', function () {
    $user = User::factory()->create();

    event(new CurrentDeviceLogout('web', $user));
    event(new OtherDeviceLogout('web', $user));
    Tendril::flush();

    expect(storedEvents('auth.logout')->pluck('properties')->pluck('reason')->sort()->values()->all())
        ->toBe(['current_device', 'other_devices']);
});

it('records failed logins only when opted in and never the credentials', function () {
    $user = User::factory()->create();

    event(new Failed('web', $user, ['email' => $user->email, 'password' => 'canary-password-123']));
    Tendril::flush();
    expect(storedEvents())->toHaveCount(0);

    config(['tendril.auth.track_failed' => true]);
    event(new Failed('web', $user, ['email' => $user->email, 'password' => 'canary-password-123']));
    Tendril::flush();

    $failed = storedEvents('auth.failed')->sole();

    expect($failed->actor_id)->toBeNull()
        ->and(json_encode($failed))->not->toContain('canary-password-123')
        ->and(json_encode($failed))->not->toContain($user->email);
});

it('links the anonymous visitor to the user on login with an audited alias range', function () {
    $user = User::factory()->create();

    carry($this->get('/pricing'));
    $anonymous = storedEvents('page.viewed')->sole()->anonymous_id;
    carry($this->post("/demo/login/{$user->id}"));

    $alias = db()->table(Tables::ALIASES)->sole();

    expect($alias->anonymous_id)->toBe($anonymous)
        ->and($alias->actor_id)->toBe((string) $user->id)
        ->and($alias->valid_to)->toBeNull()
        ->and(db()->table(Tables::AUDIT)->where('action', 'alias.linked')->count())->toBe(1);

    carry($this->post('/demo/logout'));

    expect(db()->table(Tables::ALIASES)->sole()->valid_to)->not->toBeNull()
        ->and(db()->table(Tables::AUDIT)->where('action', 'alias.closed')->count())->toBe(1);
});
