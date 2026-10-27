<?php

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use VitisStudio\Tendril\Core\Identity\ActorRef;
use VitisStudio\Tendril\Core\Identity\TenantRef;
use VitisStudio\Tendril\Core\Presence\PresenceManager;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Filament\Pages\Events;
use VitisStudio\Tendril\Filament\Pages\Presence;
use VitisStudio\Tendril\Filament\Pages\Users;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    [$this->teamA, $this->teamB] = Team::factory()->count(2)->create();
    $this->admin->teams()->attach([$this->teamA->id, $this->teamB->id]);

    $type = $this->teamA->getMorphClass();
    // Always inside today's UTC day, whenever the suite runs.
    $today = CarbonImmutable::now('UTC')->startOfDay()->addSecond();

    foreach (['7001', '7002'] as $id) {
        writeEvent('report.exported', $today, ['actor_type' => 'App\\Models\\User', 'actor_id' => $id, 'tenant_type' => $type, 'tenant_id' => (string) $this->teamA->id]);
    }

    foreach (['8001', '8002', '8003'] as $id) {
        writeEvent('report.exported', $today, ['actor_type' => 'App\\Models\\User', 'actor_id' => $id, 'tenant_type' => $type, 'tenant_id' => (string) $this->teamB->id]);
    }

    aggregate();
    $this->actingAs($this->admin);
});

it('scopes reports to the current Filament tenant by default', function () {
    Filament::setCurrentPanel(Filament::getPanel('team'));
    Filament::setTenant($this->teamA);

    $scope = TendrilFilament::scope();

    expect($scope->mode)->toBe('tenant')
        ->and($scope->tenant?->id)->toBe((string) $this->teamA->id)
        ->and(app(ProductMetrics::class)->dau(CarbonImmutable::now('UTC'), $scope))->toBe(2);

    Livewire::test(Users::class)
        ->assertOk()
        ->assertSee('7001')
        ->assertSee('7002')
        ->assertDontSee('8001');

    Filament::setTenant($this->teamB);

    Livewire::test(Users::class)->assertSee('8003')->assertDontSee('7001');
});

it('refuses cross-tenant timelines even with a crafted actor parameter', function () {
    Filament::setCurrentPanel(Filament::getPanel('team'));
    Filament::setTenant($this->teamA);

    $page = Livewire::test(Users::class, ['actor' => 'App\\Models\\User|8001']);

    expect($page->instance()->timeline())->toBeNull();

    $own = Livewire::test(Users::class, ['actor' => 'App\\Models\\User|7001']);

    expect($own->instance()->timeline()['actor']->id)->toBe('7001');
});

it('fails closed when a tenant-aware panel has no tenant', function () {
    Filament::setCurrentPanel(Filament::getPanel('team'));
    Filament::setTenant(null);

    expect(TendrilFilament::scope()->mode)->toBe('none')
        ->and(app(ProductMetrics::class)->dau(CarbonImmutable::now('UTC'), TendrilFilament::scope()))->toBe(0);
});

it('reports across tenants in a panel without tenancy, unless overridden', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(TendrilFilament::scope()->isAll())->toBeTrue()
        ->and(app(ProductMetrics::class)->dau(CarbonImmutable::now('UTC'), TendrilFilament::scope()))->toBe(5);

    Filament::getPanel('admin')->getPlugin('tendril')->tenantScopeUsing(fn () => new TenantRef($this->teamB->getMorphClass(), (string) $this->teamB->id));

    expect(app(ProductMetrics::class)->dau(CarbonImmutable::now('UTC'), TendrilFilament::scope()))->toBe(3);
});

it('tags captured events with the panel tenant', function () {
    Filament::setCurrentPanel(Filament::getPanel('team'));
    Filament::setTenant($this->teamB);

    VitisStudio\Tendril\Core\Facades\Tendril::track('panel.action');
    VitisStudio\Tendril\Core\Facades\Tendril::flush();

    expect(storedEvents('panel.action')->sole()->tenant_id)->toBe((string) $this->teamB->id)
        ->and(TenantScope::tenant(new TenantRef($this->teamB->getMorphClass(), (string) $this->teamB->id))->cacheKey())->not->toBe(TenantScope::all()->cacheKey());
});

it('scopes the event catalog and inspector to the panel tenant', function () {
    $type = $this->teamA->getMorphClass();
    $today = CarbonImmutable::now('UTC')->startOfDay()->addSecond();

    writeEvent('team_a.only', $today, ['actor_type' => 'App\\Models\\User', 'actor_id' => '7001', 'tenant_type' => $type, 'tenant_id' => (string) $this->teamA->id]);
    writeEvent('team_b.only', $today, ['actor_type' => 'App\\Models\\User', 'actor_id' => '8001', 'tenant_type' => $type, 'tenant_id' => (string) $this->teamB->id]);
    aggregate();

    Filament::setCurrentPanel(Filament::getPanel('team'));
    Filament::setTenant($this->teamA);

    Livewire::test(Events::class)->assertOk()->assertSee('team_a.only')->assertDontSee('team_b.only');

    $foreign = Livewire::test(Events::class, ['event' => 'team_b.only']);

    expect(json_encode($foreign->instance()->inspection()))->not->toContain('8001');
});

it('scopes presence to the panel tenant and fails closed without one', function () {
    $type = $this->teamA->getMorphClass();
    $manager = app(PresenceManager::class);
    $manager->touch(new ActorRef('App\\Models\\User', '7001'), new TenantRef($type, (string) $this->teamA->id));
    $manager->touch(new ActorRef('App\\Models\\User', '8001'), new TenantRef($type, (string) $this->teamB->id));

    Filament::setCurrentPanel(Filament::getPanel('team'));
    Filament::setTenant($this->teamA);

    $presence = Livewire::test(Presence::class)->assertOk()->instance()->presence();

    expect($presence['count'])->toBe(1)
        ->and(collect($presence['members'])->pluck('id')->all())->toBe(['7001']);

    Filament::setTenant(null);

    expect(Livewire::test(Presence::class)->instance()->presence()['count'])->toBe(0);
});
