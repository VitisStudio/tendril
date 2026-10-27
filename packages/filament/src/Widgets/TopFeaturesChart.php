<?php

namespace VitisStudio\Tendril\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use VitisStudio\Tendril\Filament\Widgets\Concerns\TendrilWidget;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

class TopFeaturesChart extends ChartWidget
{
    use TendrilWidget;

    public function getHeading(): ?string
    {
        return 'Top features by unique actors';
    }

    protected function getData(): array
    {
        $top = app(ProductMetrics::class)->topFeatures($this->from(), $this->to(), $this->tenantScope(), 10);

        return [
            'datasets' => [['label' => 'Unique actors', 'data' => array_column($top, 'uniques')]],
            'labels' => array_column($top, 'feature'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
