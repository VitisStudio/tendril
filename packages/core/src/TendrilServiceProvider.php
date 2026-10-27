<?php

namespace VitisStudio\Tendril\Core;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;
use VitisStudio\Tendril\Core\Acquisition\AcquisitionParser;
use VitisStudio\Tendril\Core\Auth\AuthEventSubscriber;
use VitisStudio\Tendril\Core\Capture\Buffer;
use VitisStudio\Tendril\Core\Capture\EventNormalizer;
use VitisStudio\Tendril\Core\Capture\Flusher;
use VitisStudio\Tendril\Core\Capture\NameCardinalityGuard;
use VitisStudio\Tendril\Core\Capture\Recorder;
use VitisStudio\Tendril\Core\Commands\DeadLettersCommand;
use VitisStudio\Tendril\Core\Commands\DoctorCommand;
use VitisStudio\Tendril\Core\Commands\DrainCommand;
use VitisStudio\Tendril\Core\Commands\EraseCommand;
use VitisStudio\Tendril\Core\Commands\InspectCommand;
use VitisStudio\Tendril\Core\Commands\PartitionsCommand;
use VitisStudio\Tendril\Core\Commands\PruneCommand;
use VitisStudio\Tendril\Core\Commands\RebuildTouchpointsCommand;
use VitisStudio\Tendril\Core\Context\ContextStore;
use VitisStudio\Tendril\Core\Context\LifecycleSubscriber;
use VitisStudio\Tendril\Core\Contracts\ActorResolver;
use VitisStudio\Tendril\Core\Contracts\BufferStore;
use VitisStudio\Tendril\Core\Contracts\ConsentResolver;
use VitisStudio\Tendril\Core\Contracts\PresenceStore;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;
use VitisStudio\Tendril\Core\Decisions\DecisionPipeline;
use VitisStudio\Tendril\Core\Diagnostics\CoreChecks;
use VitisStudio\Tendril\Core\Diagnostics\Diagnostics;
use VitisStudio\Tendril\Core\Http\Controllers\BeaconScriptController;
use VitisStudio\Tendril\Core\Http\Controllers\CollectController;
use VitisStudio\Tendril\Core\Http\Middleware\TrackRequests;
use VitisStudio\Tendril\Core\Http\RequestLifecycle;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\AliasWriter;
use VitisStudio\Tendril\Core\Identity\AuthActorResolver;
use VitisStudio\Tendril\Core\Identity\DenyConsentResolver;
use VitisStudio\Tendril\Core\Identity\NullTenantResolver;
use VitisStudio\Tendril\Core\Livewire\LivewireActionHook;
use VitisStudio\Tendril\Core\Livewire\LivewireRecorder;
use VitisStudio\Tendril\Core\Presence\CachePresenceStore;
use VitisStudio\Tendril\Core\Presence\PresenceManager;
use VitisStudio\Tendril\Core\Presence\RedisPresenceStore;
use VitisStudio\Tendril\Core\Privacy\ErasureManager;
use VitisStudio\Tendril\Core\Sessions\SessionManager;
use VitisStudio\Tendril\Core\Storage\Archiver;
use VitisStudio\Tendril\Core\Storage\Audit;
use VitisStudio\Tendril\Core\Storage\BatchWriter;
use VitisStudio\Tendril\Core\Storage\DeadLetters;
use VitisStudio\Tendril\Core\Storage\DirtyBuckets;
use VitisStudio\Tendril\Core\Storage\IngestionStats;
use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Core\Storage\Pruner;
use VitisStudio\Tendril\Core\Storage\RowInserter;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\StateStore;
use VitisStudio\Tendril\Core\Transport\Buffer\RedisBufferStore;
use VitisStudio\Tendril\Core\Transport\BufferDrainer;
use VitisStudio\Tendril\Core\Transport\Transports;

final class TendrilServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tendril')
            ->hasConfigFile('tendril')
            ->hasCommands([
                DoctorCommand::class,
                InspectCommand::class,
                DrainCommand::class,
                PruneCommand::class,
                EraseCommand::class,
                DeadLettersCommand::class,
                PartitionsCommand::class,
                RebuildTouchpointsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $app = $this->app;

        $app->singleton(Tendril::class);
        $app->singleton(Database::class);
        $app->singleton(StateStore::class);
        $app->singleton(ContextStore::class);
        $app->singleton(Recorder::class);
        $app->singleton(Flusher::class);
        $app->singleton(NameCardinalityGuard::class);
        $app->singleton(IngestionStats::class);
        $app->singleton(DirtyBuckets::class);
        $app->singleton(DeadLetters::class);
        $app->singleton(Audit::class);
        $app->singleton(AliasWriter::class);
        $app->singleton(BatchWriter::class);
        $app->singleton(Transports::class);
        $app->singleton(DecisionPipeline::class, fn () => new DecisionPipeline);
        $app->singleton(MaintenanceManager::class);
        $app->singleton(Diagnostics::class);
        $app->singleton(PresenceManager::class);
        $app->singleton(SessionManager::class);
        $app->singleton(AcquisitionParser::class);
        $app->singleton(LivewireRecorder::class);
        $app->singleton(ErasureManager::class);
        $app->singleton(Archiver::class);
        $app->singleton(Pruner::class);
        $app->singleton(BufferDrainer::class);

        // Per unit of work: fresh per Octane request / queue job.
        $app->scoped(Buffer::class);
        $app->scoped(RequestLifecycle::class);

        // Read config on every resolution so runtime config changes apply.
        $app->bind(EventNormalizer::class, fn () => EventNormalizer::fromConfig());
        $app->bind(RowInserter::class, fn (Application $app) => new RowInserter($app->make(Database::class)));

        $app->bind(ActorResolver::class, fn (Application $app) => $app->make($this->resolverClass('actor', AuthActorResolver::class)));
        $app->bind(TenantResolver::class, fn (Application $app) => $app->make($this->resolverClass('tenant', NullTenantResolver::class)));
        $app->bind(ConsentResolver::class, fn (Application $app) => $app->make($this->resolverClass('consent', DenyConsentResolver::class)));

        $app->bind(PresenceStore::class, fn () => config('tendril.presence.store') === 'redis'
            ? new RedisPresenceStore((string) config('tendril.presence.redis_connection', 'default'))
            : new CachePresenceStore(is_string(config('tendril.presence.cache_store')) ? config('tendril.presence.cache_store') : null));

        $app->bind(BufferStore::class, fn () => RedisBufferStore::fromConfig());
    }

    public function packageBooted(): void
    {
        if (config('tendril.database.run_migrations', true)) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__).'/database/migrations' => database_path('migrations'),
            ], 'tendril-migrations');

            $this->publishes([
                dirname(__DIR__).'/resources/js/tendril-beacon.js' => public_path('vendor/tendril/tendril-beacon.js'),
            ], 'tendril-assets');
        }

        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $events->subscribe(LifecycleSubscriber::class);
        $events->subscribe(AuthEventSubscriber::class);

        foreach (CoreChecks::all() as $check) {
            $this->app->make(Diagnostics::class)->register($check);
        }

        $this->registerMiddleware();
        $this->registerContextHooks();
        $this->registerLivewire();
        $this->registerBeacon();

        // Fallback flush for work that never passed through the middleware.
        $this->app->terminating(function (): void {
            try {
                $this->app->make(Flusher::class)->flush();
            } catch (Throwable) {
                // Fail open.
            }
        });
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('tendril', TrackRequests::class);

        foreach ((array) config('tendril.http.middleware_groups', ['web']) as $group) {
            if (is_string($group) && $group !== '') {
                $router->pushMiddlewareToGroup($group, TrackRequests::class);
            }
        }
    }

    /**
     * Jobs dispatched during a request inherit the actor/tenant as hidden
     * Context (IDs only), resolved at dispatch time.
     */
    private function registerContextHooks(): void
    {
        Context::dehydrating(function (ContextRepository $context): void {
            try {
                if (! Recorder::enabled() || (! $context->has(ContextStore::REQUEST_ID) && ! $context->hasHidden(ContextStore::REQUEST_ID))) {
                    return;
                }

                if (! $context->hasHidden(ContextStore::ACTOR)) {
                    $actor = $this->app->make(ActorResolver::class)->resolve();

                    if ($actor instanceof ActorRef) {
                        $context->addHidden(ContextStore::ACTOR, $actor->toArray());
                    }
                }

                if (! $context->hasHidden(ContextStore::TENANT)) {
                    $tenant = $this->app->make(TenantResolver::class)->resolve();

                    if ($tenant !== null) {
                        $context->addHidden(ContextStore::TENANT, $tenant->toArray());
                    }
                }
            } catch (Throwable) {
                // Never block a dispatch.
            }
        });
    }

    private function registerLivewire(): void
    {
        if (! config('tendril.livewire.enabled', true) || ! class_exists(\Livewire\Livewire::class) || ! class_exists(\Livewire\ComponentHook::class)) {
            return;
        }

        try {
            \Livewire\Livewire::componentHook(LivewireActionHook::class);
        } catch (Throwable) {
            // Livewire API unavailable: capture degrades, the app does not.
        }
    }

    private function registerBeacon(): void
    {
        RateLimiter::for('tendril-beacon', function (Request $request) {
            $key = $request->hasSession() ? $request->session()->getId() : sha1((string) $request->ip());

            return Limit::perMinute(max(1, (int) config('tendril.beacon.rate_limit_per_minute', 120)))->by('tendril-beacon|'.$key);
        });

        $prefix = trim((string) config('tendril.beacon.prefix', 'tendril'), '/');
        $middleware = array_values(array_filter((array) config('tendril.beacon.middleware', ['web']), 'is_string'));

        Route::middleware($middleware)->prefix($prefix)->group(function (): void {
            Route::post('collect', CollectController::class)
                ->name('tendril.collect')
                ->middleware('throttle:tendril-beacon')
                ->withoutMiddleware([
                    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
                    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                ]);

            Route::get('beacon.js', BeaconScriptController::class)->name('tendril.beacon');
        });
    }

    /**
     * @param  class-string  $default
     * @return class-string
     */
    private function resolverClass(string $key, string $default): string
    {
        $class = config("tendril.resolvers.{$key}");

        return is_string($class) && class_exists($class) ? $class : $default;
    }
}
