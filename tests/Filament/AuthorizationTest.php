<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use VitisStudio\Tendril\Filament\Pages\Features;
use VitisStudio\Tendril\Filament\Pages\Health;
use VitisStudio\Tendril\Filament\Pages\Overview;
use VitisStudio\Tendril\Filament\Pages\Users;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;
use VitisStudio\Tendril\Filament\Widgets\ActivityStats;
use VitisStudio\Tendril\Filament\Widgets\DailyActiveChart;
use Workbench\App\Models\User;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
});

it('denies guests and users without the viewTendril ability', function () {
    expect(Overview::canAccess())->toBeFalse();

    $this->actingAs($this->member);
    expect(Overview::canAccess())->toBeFalse()
        ->and(ActivityStats::canView())->toBeFalse()
        ->and(Overview::shouldRegisterNavigation())->toBeFalse();

    $this->actingAs($this->admin);
    expect(Overview::canAccess())->toBeTrue()->and(ActivityStats::canView())->toBeTrue();
});

it('enforces authorization on every Livewire request for pages and widgets', function () {
    $this->actingAs($this->member);

    Livewire::test(Overview::class)->assertForbidden();
    Livewire::test(Users::class)->assertForbidden();
    Livewire::test(DailyActiveChart::class)->assertForbidden();

    $this->actingAs($this->admin);

    Livewire::test(Overview::class)->assertOk();
    Livewire::test(DailyActiveChart::class)->assertOk();
});

it('protects page routes', function () {
    $this->actingAs($this->member)->get(Overview::getUrl(panel: 'admin'))->assertForbidden();
    $this->actingAs($this->admin)->get(Overview::getUrl(panel: 'admin'))->assertOk();
});

it('honours a per-panel authorize callback', function () {
    Filament::getPanel('admin')->getPlugin('tendril')->authorize(fn (User $user) => $user->email === 'special@example.test');

    $this->actingAs($this->admin);
    expect(TendrilFilament::authorized())->toBeFalse();

    $this->actingAs(User::factory()->create(['email' => 'special@example.test']));
    expect(TendrilFilament::authorized())->toBeTrue();
});

it('separates viewing from managing definitions and operational pages', function () {
    Gate::define('manageTendril', fn (User $user) => false);
    $this->actingAs($this->admin);

    expect(Overview::canAccess())->toBeTrue()
        ->and(TendrilFilament::canManage())->toBeFalse()
        ->and(Health::canAccess())->toBeFalse();

    Livewire::test(Features::class)->assertOk()->assertActionHidden('create');
    Livewire::test(Health::class)->assertForbidden();
});
