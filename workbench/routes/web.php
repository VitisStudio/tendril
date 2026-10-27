<?php

use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use VitisStudio\Tendril\Core\Facades\Tendril;
use Workbench\App\Jobs\TrackFromJob;
use Workbench\App\Models\User;

Route::middleware('web')->group(function (): void {
    Route::get('/', fn () => response('<html><body>Home</body></html>'))->name('home');
    Route::get('/pricing', fn () => response('<html><body>Pricing</body></html>'))->name('pricing');
    Route::get('/reports/{report}', fn (string $report) => response("<html><body>Report {$report}</body></html>"))->name('reports.show');
    Route::get('/docs/{page}', fn (string $page) => response("<html><body>{$page}</body></html>")); // unnamed: URI pattern fallback
    Route::get('/up', fn () => response('ok'))->name('health');
    Route::get('/api-ish', fn () => response()->json(['ok' => true]))->name('json');
    Route::get('/go', fn () => redirect('/pricing'))->name('go');

    Route::post('/demo/login/{user}', function (Request $request, User $user) {
        Auth::login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return response('logged in');
    })->name('demo.login');

    Route::post('/demo/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response('logged out');
    })->name('demo.logout');

    Route::post('/demo/register', function (Request $request) {
        $user = User::query()->create([
            'name' => 'New user',
            'email' => 'new-'.uniqid().'@example.test',
            'password' => 'secret-password',
        ]);

        event(new Registered($user));
        Auth::login($user);

        return response('registered');
    })->name('demo.register');

    Route::post('/demo/checkout', function (Request $request) {
        // Canary payloads: none of these may be stored.
        Tendril::track('order.completed', [
            'revenue' => '49.00',
            'currency' => 'USD',
            'password' => $request->input('password'),
            'card_number' => $request->input('card_number'),
        ]);

        return response('ok');
    })->name('demo.checkout');

    Route::post('/demo/dispatch', function () {
        Tendril::track('checkout.started');
        TrackFromJob::dispatch('job.ran');

        return response('dispatched');
    })->name('demo.dispatch');
});
