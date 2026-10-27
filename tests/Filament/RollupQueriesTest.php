<?php

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use VitisStudio\Tendril\Core\Facades\Tendril;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Filament\Pages\Conversions;
use VitisStudio\Tendril\Filament\Pages\Overview;
use VitisStudio\Tendril\Filament\Widgets\ActivityStats;
use VitisStudio\Tendril\Filament\Widgets\ConversionTrendChart;
use VitisStudio\Tendril\Filament\Widgets\DailyActiveChart;
use VitisStudio\Tendril\Filament\Widgets\EventVolumeChart;
use VitisStudio\Tendril\Filament\Widgets\FeatureTrendChart;
use VitisStudio\Tendril\Filament\Widgets\TopFeaturesChart;
use VitisStudio\Tendril\Marketing\Conversions\ConversionDefinition;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;
use Workbench\App\Models\User;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->create());

    writeEvent('report.exported', CarbonImmutable::now('UTC')->subHour(), actor('1'));
    aggregate();

    $this->queries = [];
    DB::listen(fn ($query) => $this->queries[] = $query->sql);
});

it('renders dashboard widgets from rollups and activity tables only', function (string $widget) {
    Livewire::test($widget, ['days' => 30])->assertOk();

    $touched = collect($this->queries)->filter(fn (string $sql) => str_contains($sql, Tables::EVENTS));

    expect($touched)->toBeEmpty('Widget '.$widget.' queried raw events: '.$touched->implode(' | '));
})->with([
    ActivityStats::class,
    DailyActiveChart::class,
    EventVolumeChart::class,
    TopFeaturesChart::class,
    FeatureTrendChart::class,
    ConversionTrendChart::class,
]);

it('builds the overview page without touching raw events', function () {
    Livewire::test(Overview::class)->assertOk()->assertSee('Top events')->assertSee('report.exported');

    expect(collect($this->queries)->filter(fn (string $sql) => str_contains($sql, Tables::EVENTS)))->toBeEmpty()
        ->and(collect($this->queries)->contains(fn (string $sql) => str_contains($sql, Tables::ROLLUPS)))->toBeTrue()
        ->and(collect($this->queries)->contains(fn (string $sql) => str_contains($sql, Tables::ACTIVITY)))->toBeTrue();
});

it('ships widgets with polling off', function () {
    foreach ([ActivityStats::class, DailyActiveChart::class, EventVolumeChart::class] as $widget) {
        expect((new $widget)->getPollingInterval())->toBeNull();
    }
});

it('lists code-defined conversions in the table alongside data ones, read-only', function () {
    Tendril::conversion('paid-signup')->name('Paid signup')->event('signup.paid');
    app(ConversionRepository::class)->save(ConversionDefinition::fromArray(['key' => 'demo-booked', 'name' => 'Demo booked', 'definition' => ['event' => 'demo.booked']]));
    writeEvent('signup.paid', CarbonImmutable::now('UTC')->subHour(), actor('2'));
    aggregate();

    Livewire::test(Conversions::class)
        ->assertOk()
        ->assertCanSeeTableRecords(['paid-signup', 'demo-booked'])
        ->assertTableColumnStateSet('total', '1', 'paid-signup')
        ->assertTableColumnStateSet('source', 'code', 'paid-signup')
        ->assertTableActionHidden('edit', 'paid-signup')
        ->assertTableActionHidden('delete', 'paid-signup')
        ->assertTableActionVisible('edit', 'demo-booked')
        ->searchTable('paid')
        ->assertCanSeeTableRecords(['paid-signup'])
        ->assertCanNotSeeTableRecords(['demo-booked']);
});
