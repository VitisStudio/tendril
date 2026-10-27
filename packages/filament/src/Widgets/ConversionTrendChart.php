<?php

namespace VitisStudio\Tendril\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use VitisStudio\Tendril\Filament\Widgets\Concerns\TendrilWidget;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

/**
 * Conversions per day from conversion rollups (count dimensions only).
 */
class ConversionTrendChart extends ChartWidget
{
    use TendrilWidget;

    public ?string $conversion = null;

    public function getHeading(): ?string
    {
        return $this->conversion !== null ? "Conversions: {$this->conversion}" : 'Conversions';
    }

    protected function getData(): array
    {
        $series = app(ProductMetrics::class)->series('conversion', $this->conversion, $this->from(), $this->to(), $this->tenantScope(), 'day', excludeCurrencyDimensions: true);

        return [
            'datasets' => [['label' => 'Conversions', 'data' => array_values($series)]],
            'labels' => array_keys($series),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
