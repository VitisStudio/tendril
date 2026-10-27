<?php

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use VitisStudio\Tendril\Core\TendrilServiceProvider;
use VitisStudio\Tendril\Destinations\DestinationsServiceProvider;
use VitisStudio\Tendril\Filament\TendrilFilamentServiceProvider;
use VitisStudio\Tendril\Marketing\MarketingServiceProvider;
use VitisStudio\Tendril\Product\ProductServiceProvider;
use VitisStudio\Tendril\Timescale\TimescaleServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * Runs against the database named by DB_CONNECTION (pgsql / mysql in CI,
 * in-memory SQLite locally). SQLite is a convenience only; CI proves both
 * PostgreSQL and MySQL.
 */
abstract class BaseTestCase extends Orchestra
{
    protected $enablesPackageDiscoveries = true;

    protected function getPackageProviders($app): array
    {
        $framework = array_values(array_filter([
            'Filament\Actions\ActionsServiceProvider',
            'RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider',
            'BladeUI\Heroicons\BladeHeroiconsServiceProvider',
            'BladeUI\Icons\BladeIconsServiceProvider',
            'Filament\FilamentServiceProvider',
            'Filament\Forms\FormsServiceProvider',
            'Filament\Infolists\InfolistsServiceProvider',
            'Livewire\LivewireServiceProvider',
            'Filament\Notifications\NotificationsServiceProvider',
            'Filament\Schemas\SchemasServiceProvider',
            'Filament\Support\SupportServiceProvider',
            'Filament\Tables\TablesServiceProvider',
            'Filament\Widgets\WidgetsServiceProvider',
        ], 'class_exists'));

        return [
            ...$framework,
            TendrilServiceProvider::class,
            ProductServiceProvider::class,
            MarketingServiceProvider::class,
            TendrilFilamentServiceProvider::class,
            TimescaleServiceProvider::class,
            DestinationsServiceProvider::class,
            WorkbenchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('database.default', env('DB_CONNECTION', 'testing'));
        $config->set('app.timezone', 'UTC');
        $config->set('queue.default', 'sync');
        // Batches and failed jobs live on the test database, not Testbench's file-backed sqlite.
        $config->set('queue.batching.database', env('DB_CONNECTION', 'testing'));
        $config->set('queue.failed.database', env('DB_CONNECTION', 'testing'));
        $config->set('cache.default', 'array');
        $config->set('session.driver', 'array');
        $config->set('auth.providers.users.model', User::class);

        $config->set('tendril.transport.driver', 'sync');
        $config->set('tendril.environment', 'testing');
        // Surface capture bugs in tests; fail-open tests switch this off explicitly.
        $config->set('tendril.debug.throw', true);
        $config->set('tendril.presence.store', 'cache');
    }
}
