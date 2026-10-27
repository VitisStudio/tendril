<?php

use Filament\Facades\Filament;
use VitisStudio\Tendril\Core\Http\Middleware\TrackRequests;
use VitisStudio\Tendril\Filament\Pages\Campaigns;
use VitisStudio\Tendril\Filament\Pages\CohortsRetention;
use VitisStudio\Tendril\Filament\Pages\Conversions;
use VitisStudio\Tendril\Filament\Pages\Events;
use VitisStudio\Tendril\Filament\Pages\Features;
use VitisStudio\Tendril\Filament\Pages\Funnels;
use VitisStudio\Tendril\Filament\Pages\Health;
use VitisStudio\Tendril\Filament\Pages\Overview;
use VitisStudio\Tendril\Filament\Pages\Presence;
use VitisStudio\Tendril\Filament\Pages\Settings;
use VitisStudio\Tendril\Filament\Pages\Users;
use VitisStudio\Tendril\Filament\TendrilPlugin;

it('registers every page in each panel that uses the plugin', function (string $panelId) {
    $panel = Filament::getPanel($panelId);

    expect($panel->hasPlugin('tendril'))->toBeTrue()
        ->and($panel->getPages())->toContain(
            Overview::class, Users::class, Features::class, Events::class, Funnels::class,
            CohortsRetention::class, Presence::class, Health::class, Settings::class,
            Conversions::class, Campaigns::class,
        )
        ->and($panel->getMiddleware())->toContain(TrackRequests::class);
})->with(['admin', 'team']);

it('keeps plugin configuration per panel', function () {
    expect(Filament::getPanel('admin')->getPlugin('tendril')->getNavigationGroup())->toBe('Analytics')
        ->and(Filament::getPanel('team')->getPlugin('tendril')->getNavigationGroup())->toBe('Insights');
});

it('lets hosts choose pages', function () {
    $plugin = TendrilPlugin::make()->withoutPages([Settings::class, Health::class]);

    expect($plugin->getPages())->not->toContain(Settings::class)
        ->and($plugin->getPages())->toContain(Overview::class)
        ->and(TendrilPlugin::make()->pages([Overview::class])->getPages())->toBe([Overview::class]);
});

it('uses tendril/ slugs', function () {
    expect(Overview::getSlug())->toBe('tendril/overview')
        ->and(CohortsRetention::getSlug())->toBe('tendril/cohorts');
});
