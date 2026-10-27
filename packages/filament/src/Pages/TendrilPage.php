<?php

namespace VitisStudio\Tendril\Filament\Pages;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Filament\Concerns\AuthorizesTendril;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;

/**
 * Shared page conventions: navigation under the plugin's group, slugs under
 * "tendril/", authorization on every request, tenant scope from the panel,
 * and a manual Refresh (analytics widgets never poll by default).
 */
abstract class TendrilPage extends Page
{
    use AuthorizesTendril;

    /** Range selector shared by pages: 7, 30 or 90 days. */
    public int $days = 30;

    abstract public static function tendrilKey(): string;

    abstract public static function tendrilLabel(): string;

    abstract public static function tendrilIcon(): string;

    abstract public static function tendrilSort(): int;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'tendril/'.static::tendrilKey();
    }

    public static function getNavigationLabel(): string
    {
        return static::tendrilLabel();
    }

    public static function getNavigationIcon(): ?string
    {
        return static::tendrilIcon();
    }

    public static function getNavigationGroup(): ?string
    {
        return TendrilFilament::plugin()?->getNavigationGroup() ?? 'Analytics';
    }

    public static function getNavigationSort(): ?int
    {
        return static::tendrilSort();
    }

    public static function canAccess(): bool
    {
        return TendrilFilament::authorized();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getTitle(): string
    {
        return static::tendrilLabel();
    }

    public function getView(): string
    {
        return 'tendril-filament::pages.'.static::tendrilKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('range')
                ->label(fn () => "Last {$this->days} days")
                ->icon('heroicon-o-calendar')
                ->color('gray')
                ->action(function (): void {
                    $this->days = match ($this->days) {
                        7 => 30,
                        30 => 90,
                        default => 7,
                    };
                    $this->dispatch('tendril-range', days: $this->days);
                }),
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->dispatch('tendril-refresh')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return ['days' => $this->days];
    }

    protected function rangeStart(): CarbonImmutable
    {
        return Instant::day(Instant::now())->subDays(max(1, $this->days) - 1);
    }

    protected function rangeEnd(): CarbonImmutable
    {
        return Instant::day(Instant::now());
    }
}
