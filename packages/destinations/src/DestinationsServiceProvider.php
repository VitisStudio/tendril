<?php

namespace VitisStudio\Tendril\Destinations;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VitisStudio\Tendril\Destinations\Commands\DispatchCommand;

final class DestinationsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tendril-destinations')
            ->hasConfigFile('tendril-destinations')
            ->hasCommand(DispatchCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(DestinationManager::class);
        $this->app->singleton(DeliveryDispatcher::class);
    }

    public function packageBooted(): void
    {
        if (config('tendril.database.run_migrations', true)) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([dirname(__DIR__).'/database/migrations' => database_path('migrations')], 'tendril-destinations-migrations');
        }
    }
}
