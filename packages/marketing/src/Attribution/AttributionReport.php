<?php

namespace VitisStudio\Tendril\Marketing\Attribution;

use JsonSerializable;

/**
 * Every report is stamped with its model and lookback window.
 */
final class AttributionReport implements JsonSerializable
{
    /**
     * @param  list<string>  $conversions
     * @param  list<array{source: string, medium: string, campaign: string, conversions: float, values: array<string, int>}>  $rows
     * @param  array<string, int>  $values
     */
    public function __construct(
        public readonly string $model,
        public readonly string $modelLabel,
        public readonly int $lookbackDays,
        public readonly string $from,
        public readonly string $to,
        public readonly array $conversions,
        public readonly array $rows,
        public readonly int $totalConversions,
        public readonly array $values,
    ) {}

    public function stamp(): string
    {
        return "{$this->modelLabel}, {$this->lookbackDays}-day lookback";
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'model' => $this->model,
            'model_label' => $this->modelLabel,
            'lookback_days' => $this->lookbackDays,
            'stamp' => $this->stamp(),
            'from' => $this->from,
            'to' => $this->to,
            'conversions' => $this->conversions,
            'total_conversions' => $this->totalConversions,
            'values' => $this->values,
            'rows' => $this->rows,
        ];
    }
}
