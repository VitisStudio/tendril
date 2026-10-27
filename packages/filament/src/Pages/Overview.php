<?php

namespace VitisStudio\Tendril\Filament\Pages;

use VitisStudio\Tendril\Filament\Widgets\ActivityStats;
use VitisStudio\Tendril\Filament\Widgets\ConversionTrendChart;
use VitisStudio\Tendril\Filament\Widgets\DailyActiveChart;
use VitisStudio\Tendril\Filament\Widgets\EventVolumeChart;
use VitisStudio\Tendril\Filament\Widgets\TopFeaturesChart;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

/**
 * Active now, DAU/WAU/MAU, sessions, feature and conversion trends.
 */
class Overview extends TendrilPage
{
    public static function tendrilKey(): string
    {
        return 'overview';
    }

    public static function tendrilLabel(): string
    {
        return 'Overview';
    }

    public static function tendrilIcon(): string
    {
        return 'heroicon-o-chart-bar-square';
    }

    public static function tendrilSort(): int
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        $widgets = [ActivityStats::class, DailyActiveChart::class, EventVolumeChart::class, TopFeaturesChart::class];

        if (class_exists(\VitisStudio\Tendril\Marketing\MarketingServiceProvider::class)) {
            $widgets[] = ConversionTrendChart::class;
        }

        return $widgets;
    }

    /**
     * @return array<string, mixed>
     */
    public function overviewData(): array
    {
        $metrics = app(ProductMetrics::class);
        $scope = $this->tenantScope();

        return [
            'newVersusReturning' => $metrics->newVersusReturning($this->rangeStart(), $this->rangeEnd(), $scope),
            'topEvents' => $metrics->topEvents($this->rangeStart(), $this->rangeEnd(), $scope, 10),
            'loginFrequency' => $metrics->loginFrequency($this->rangeStart(), $this->rangeEnd(), $scope),
        ];
    }
}
