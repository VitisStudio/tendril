<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use VitisStudio\Tendril\Core\Facades\Tendril;
use Workbench\App\Livewire\ReportBuilder;
use Workbench\App\Models\User;
use Workbench\App\Providers\Filament\AdminPanelProvider;
use Workbench\App\Providers\Filament\TeamPanelProvider;

/**
 * The workbench is a real Laravel application: demo routes, a Livewire
 * component, two Filament panels (one tenant-aware) and code-defined
 * Tendril definitions. Integration tests run against it.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (class_exists(\Filament\PanelProvider::class)) {
            $this->app->register(AdminPanelProvider::class);
            $this->app->register(TeamPanelProvider::class);
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if (class_exists(Livewire::class)) {
            Livewire::component('report-builder', ReportBuilder::class);
        }

        Gate::define('viewTendril', fn (?User $user = null) => (bool) $user?->is_admin);

        if (Tendril::hasMacro('feature')) {
            Tendril::feature('reporting')
                ->name('Reporting')
                ->events(['report.exported'])
                ->routes(['reports.*'])
                ->components(['report-builder@export', 'reports.show@export']);
        }

        if (Tendril::hasMacro('conversion')) {
            Tendril::conversion('purchase')
                ->name('Purchase')
                ->event('order.completed')
                ->value('properties.revenue')
                ->currencyFrom('properties.currency');
        }
    }
}
