<?php

namespace VitisStudio\Tendril\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;
use VitisStudio\Tendril\Filament\Widgets\Concerns\TendrilWidget;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

/**
 * Daily unique actors for one feature (activity table).
 */
class FeatureTrendChart extends ChartWidget
{
    use TendrilWidget;

    public ?string $feature = null;

    public function getHeading(): ?string
    {
        return $this->feature !== null ? "Daily unique actors: {$this->feature}" : 'Select a feature to see its trend';
    }

    #[On('tendril-feature')]
    public function selectFeature(?string $feature): void
    {
        $this->feature = $feature;
    }

    protected function getData(): array
    {
        if ($this->feature === null) {
            return ['datasets' => [['label' => 'Unique actors', 'data' => []]], 'labels' => []];
        }

        $series = app(ProductMetrics::class)->dailyActive($this->from(), $this->to(), $this->tenantScope(), false, 'feature', $this->feature);

        return [
            'datasets' => [['label' => 'Unique actors', 'data' => array_values($series)]],
            'labels' => array_keys($series),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
