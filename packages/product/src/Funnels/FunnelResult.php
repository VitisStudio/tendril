<?php

namespace VitisStudio\Tendril\Product\Funnels;

use JsonSerializable;

final class FunnelResult implements JsonSerializable
{
    /**
     * @param  list<array{label: string, event: string, count: int, from_previous: float, from_start: float}>  $steps
     */
    public function __construct(
        public readonly array $steps,
        public readonly int $entered,
        public readonly int $completed,
        public readonly ?float $medianSeconds,
        public readonly ?float $averageSeconds,
        public readonly int $windowSeconds,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function completionRate(): float
    {
        return $this->entered > 0 ? round($this->completed / $this->entered, 4) : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'steps' => $this->steps,
            'entered' => $this->entered,
            'completed' => $this->completed,
            'completion_rate' => $this->completionRate(),
            'median_seconds' => $this->medianSeconds,
            'average_seconds' => $this->averageSeconds,
            'window_seconds' => $this->windowSeconds,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
