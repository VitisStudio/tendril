<?php

namespace VitisStudio\Tendril\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
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

/**
 * Register per panel: $panel->plugin(TendrilPlugin::make()). Each panel gets
 * its own instance, so navigation, authorization and tenant scoping can
 * differ between panels.
 */
class TendrilPlugin implements Plugin
{
    protected string $navigationGroup = 'Analytics';

    protected ?Closure $authorizeUsing = null;

    protected ?Closure $tenantScopeUsing = null;

    protected bool $trackPanelRequests = true;

    /** @var list<class-string>|null */
    protected ?array $pages = null;

    /** @var list<class-string> */
    protected array $excludedPages = [];

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'tendril';
    }

    public function register(Panel $panel): void
    {
        $panel->pages($this->getPages());

        if ($this->trackPanelRequests) {
            // Persistent so Livewire updates from panel pages are tracked too.
            $panel->middleware([TrackRequests::class], isPersistent: true);
        }
    }

    public function boot(Panel $panel): void {}

    public function navigationGroup(string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string
    {
        return $this->navigationGroup;
    }

    /**
     * @param  Closure(?\Illuminate\Contracts\Auth\Authenticatable): bool  $callback
     */
    public function authorize(Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function getAuthorizeUsing(): ?Closure
    {
        return $this->authorizeUsing;
    }

    /**
     * Override the report tenant scope: return a model/TenantRef, or null for
     * all tenants. By default: Filament tenant, else the core tenant resolver.
     *
     * @param  Closure(): mixed  $callback
     */
    public function tenantScopeUsing(Closure $callback): static
    {
        $this->tenantScopeUsing = $callback;

        return $this;
    }

    public function getTenantScopeUsing(): ?Closure
    {
        return $this->tenantScopeUsing;
    }

    public function trackPanelRequests(bool $condition = true): static
    {
        $this->trackPanelRequests = $condition;

        return $this;
    }

    /**
     * @param  list<class-string>  $pages
     */
    public function pages(array $pages): static
    {
        $this->pages = $pages;

        return $this;
    }

    /**
     * @param  list<class-string>  $pages
     */
    public function withoutPages(array $pages): static
    {
        $this->excludedPages = $pages;

        return $this;
    }

    /**
     * @return list<class-string>
     */
    public function getPages(): array
    {
        $pages = $this->pages ?? self::defaultPages();

        return array_values(array_filter($pages, fn (string $page) => ! in_array($page, $this->excludedPages, true)));
    }

    /**
     * @return list<class-string>
     */
    public static function defaultPages(): array
    {
        $pages = [
            Overview::class, Users::class, Features::class, Events::class, Funnels::class,
            CohortsRetention::class, Presence::class, Health::class, Settings::class,
        ];

        if (class_exists(\VitisStudio\Tendril\Marketing\MarketingServiceProvider::class)) {
            $pages[] = Conversions::class;
            $pages[] = Campaigns::class;
        }

        return $pages;
    }
}
