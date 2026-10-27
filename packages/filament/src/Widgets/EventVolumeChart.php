<?php

namespace VitisStudio\Tendril\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use VitisStudio\Tendril\Filament\Widgets\Concerns\TendrilWidget;
use VitisStudio\Tendril\Product\Metrics\ProductMetrics;

class EventVolumeChart extends ChartWidget
{
    use TendrilWidget;

    public ?string $eventName = null;

    public function getHeading(): ?string
    {
        return $this->eventName !== null ? "Volume: {$this->eventName}" : 'Event volume';
    }

    protected function getData(): array
    {
        $series = app(ProductMetrics::class)->series('event', $this->eventName, $this->from(), $this->to(), $this->tenantScope());

        return [
            'datasets' => [['label' => 'Events', 'data' => array_values($series)]],
            'labels' => array_keys($series),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
