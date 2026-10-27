<?php

namespace VitisStudio\Tendril\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VitisStudio\Tendril\Core\Presence\PresenceManager;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Filament\Widgets\Concerns\TendrilWidget;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

/**
 * Active now (presence), DAU/WAU/MAU (activity table) and sessions (rollups).
 */
class ActivityStats extends StatsOverviewWidget
{
    use TendrilWidget;

    protected function getStats(): array
    {
        $scope = $this->tenantScope();
        $metrics = app(ProductMetrics::class);
        $today = Instant::day(Instant::now());
        $sessions = $metrics->sessions($this->from(), $this->to(), $scope);
        $tenant = $scope->mode === 'tenant' ? $scope->tenant : null;

        return [
            Stat::make('Active now', $scope->mode === 'none' ? 0 : app(PresenceManager::class)->count($tenant))
                ->description('Seen in the last '.(int) round(((int) config('tendril.presence.ttl_seconds', 300)) / 60).' minutes'),
            Stat::make('DAU', $metrics->dau($today, $scope))->description('Distinct actors today (UTC)'),
            Stat::make('WAU', $metrics->wau($today, $scope))->description('Distinct actors, last 7 days'),
            Stat::make('MAU', $metrics->mau($today, $scope))->description('Distinct actors, last 30 days'),
            Stat::make('Sessions', $sessions['sessions'])->description("Last {$this->days} days"),
            Stat::make('Bounce rate', number_format($sessions['bounce_rate'] * 100, 1).'%')
                ->description('Avg. '.gmdate('H:i:s', (int) $sessions['avg_duration_seconds']).' per session'),
        ];
    }
}
