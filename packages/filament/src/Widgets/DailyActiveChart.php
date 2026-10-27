<?php

namespace VitisStudio\Tendril\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use VitisStudio\Tendril\Filament\Widgets\Concerns\TendrilWidget;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

class DailyActiveChart extends ChartWidget
{
    use TendrilWidget;

    public function getHeading(): ?string
    {
        return 'Daily active actors';
    }

    protected function getData(): array
    {
        $series = app(ProductMetrics::class)->dailyActive($this->from(), $this->to(), $this->tenantScope());

        return [
            'datasets' => [['label' => 'Active actors', 'data' => array_values($series)]],
            'labels' => array_keys($series),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
