<?php

namespace VitisStudio\Tendril\Timescale;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VitisStudio\Tendril\Core\Contracts\DiagnosticCheck;
use VitisStudio\Tendril\Core\Diagnostics\CheckResult;
use VitisStudio\Tendril\Core\Diagnostics\Diagnostics;
use VitisStudio\Tendril\Core\Storage\Maintenance\MaintenanceManager;
use VitisStudio\Tendril\Timescale\Commands\InstallCommand;

final class TimescaleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('tendril-timescale')
            ->hasConfigFile('tendril-timescale')
            ->hasCommand(InstallCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(CapabilityDetector::class);
        $this->app->singleton(TimescaleMaintenance::class);
    }

    public function packageBooted(): void
    {
        $this->app->make(MaintenanceManager::class)->extend('timescale', fn () => app(TimescaleMaintenance::class));

        $this->app->make(Diagnostics::class)->register(fn (): DiagnosticCheck => new class implements DiagnosticCheck
        {
            public function name(): string
            {
                return 'timescale';
            }

            public function run(): CheckResult
            {
                $capabilities = app(CapabilityDetector::class)->detect();
                $data = $capabilities->toArray();

                if (! $capabilities->postgres || ! $capabilities->installed) {
                    return config('tendril.storage.adapter') === 'timescale'
                        ? CheckResult::fail($this->name(), 'Storage adapter is "timescale" but TimescaleDB is not available.', $data)
                        : CheckResult::ok($this->name(), 'TimescaleDB not in use (optional).', $data);
                }

                if (config('tendril.storage.adapter') === 'timescale' && ! $capabilities->eventsIsHypertable) {
                    return CheckResult::warn($this->name(), 'TimescaleDB detected but tendril_events is not a hypertable; run tendril:timescale:install.', $data);
                }

                return CheckResult::ok($this->name(), "TimescaleDB {$capabilities->version} ({$capabilities->license} license); compression ".($capabilities->compression ? 'available' : 'unavailable').'.', $data);
            }
        }, 'timescale');
    }
}
