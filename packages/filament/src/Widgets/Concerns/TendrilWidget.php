<?php

namespace VitisStudio\Tendril\Filament\Widgets\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\On;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Filament\Concerns\AuthorizesTendril;
use VitisStudio\Tendril\Filament\Support\TendrilFilament;

/**
 * Widget conventions: authorized on every request, tenant-scoped, polling off
 * with manual refresh, range driven by the host page. Widgets read rollups
 * and activity tables only.
 */
trait TendrilWidget
{
    use AuthorizesTendril;

    public int $days = 30;

    public static function canView(): bool
    {
        return TendrilFilament::authorized();
    }

    public function getPollingInterval(): ?string
    {
        return null;
    }

    #[On('tendril-refresh')]
    public function refreshTendril(): void
    {
        // Re-render; data is recomputed from rollups on every render.
    }

    #[On('tendril-range')]
    public function setTendrilRange(int $days): void
    {
        $this->days = in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    protected function from(): CarbonImmutable
    {
        return Instant::day(Instant::now())->subDays(max(1, $this->days) - 1);
    }

    protected function to(): CarbonImmutable
    {
        return Instant::day(Instant::now());
    }
}
