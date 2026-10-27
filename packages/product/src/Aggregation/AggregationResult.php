<?php

namespace VitisStudio\Tendril\Product\Aggregation;

final class AggregationResult
{
    public int $hours = 0;

    public int $days = 0;

    public int $skipped = 0;

    public float $seconds = 0.0;

    /** True when another process held the aggregation lock and nothing ran. */
    public bool $locked = false;

    /** @var list<string> */
    public array $definitionsChanged = [];

    public function merge(self $other): void
    {
        $this->hours += $other->hours;
        $this->days += $other->days;
        $this->skipped += $other->skipped;
        $this->seconds += $other->seconds;
        $this->locked = $this->locked || $other->locked;
        $this->definitionsChanged = array_values(array_unique([...$this->definitionsChanged, ...$other->definitionsChanged]));
    }
}
