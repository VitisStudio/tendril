<?php

namespace VitisStudio\Tendril\Product\Aggregation;

use Carbon\CarbonImmutable;

/**
 * Recomputes one kind of derived data for a bucket, idempotently: delete the
 * bucket's rows for the kinds it owns, recompute from raw facts, insert.
 */
interface BucketProcessor
{
    public function key(): string;

    public function processHour(CarbonImmutable $hour, AggregationContext $context): void;

    public function processDay(CarbonImmutable $day, AggregationContext $context): void;
}
