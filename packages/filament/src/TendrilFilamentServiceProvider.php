<?php

namespace VitisStudio\Tendril\Filament;

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use VitisStudio\Tendril\Core\Contracts\TenantResolver;
use VitisStudio\Tendril\Filament\Support\FilamentTenantResolver;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;
use VitisStudio\Tendril\Filament\Widgets\ActivityStats;
use VitisStudio\Tendril\Filament\Widgets\ConversionTrendChart;
use VitisStudio\Tendril\Filament\Widgets\DailyActiveChart;
use VitisStudio\Tendril\Filament\Widgets\EventVolumeChart;
use VitisStudio\Tendril\Filament\Widgets\FeatureTrendChart;
use VitisStudio\Tendril\Filament\Widgets\TopFeaturesChart;

final class TendrilFilamentServiceProvider extends PackageServiceProvider
{
    /** @var array<string, class-string> */
    public const array WIDGETS = [
        'tendril.widgets.activity-stats' => ActivityStats::class,
        'tendril.widgets.daily-active-chart' => DailyActiveChart::class,
        'tendril.widgets.event-volume-chart' => EventVolumeChart::class,
        'tendril.widgets.top-features-chart' => TopFeaturesChart::class,
        'tendril.widgets.feature-trend-chart' => FeatureTrendChart::class,
        'tendril.widgets.conversion-trend-chart' => ConversionTrendChart::class,
    ];

    public function configurePackage(Package $package): void
    {
        $package->name('tendril-filament')->hasViews('tendril-filament');
    }

    public function packageRegistered(): void
    {
        // Captured events carry the Filament panel tenant with zero configuration.
        $this->app->extend(TenantResolver::class, fn (TenantResolver $inner) => new FilamentTenantResolver($inner));
    }

    public function packageBooted(): void
    {
        // Widgets are registered as Livewire components (not panel widgets) so
        // they never appear on the host's dashboard unless explicitly added.
        foreach (self::WIDGETS as $name => $class) {
            Livewire::component($name, $class);
        }

        // Default: nobody outside local. Hosts define the gate (their own
        // definition, registered later, wins) or use TendrilPlugin::authorize().
        if (! Gate::has(TendrilFilament::GATE)) {
            Gate::define(TendrilFilament::GATE, fn ($user = null) => app()->environment('local'));
        }
    }
}
