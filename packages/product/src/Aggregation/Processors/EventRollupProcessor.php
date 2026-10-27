<?php

namespace VitisStudio\Tendril\Product\Aggregation\Processors;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Product\Aggregation\AggregationContext;
use VitisStudio\Tendril\Product\Aggregation\BucketProcessor;
use VitisStudio\Tendril\Product\Aggregation\Rollups;

/**
 * Event and feature counts per hour; day rows are sums of hour rows
 * (counts are additive — uniques are never computed here).
 */
final class EventRollupProcessor implements BucketProcessor
{
    public const array KINDS = ['event', 'feature'];

    public function __construct(private readonly Rollups $rollups) {}

    public function key(): string
    {
        return 'events';
    }

    public function processHour(CarbonImmutable $hour, AggregationContext $context): void
    {
        $rows = [];

        foreach ($context->hourGroups($hour) as $group) {
            $count = (int) $group->aggregate;
            $name = (string) $group->name;
            Rollups::add($rows, $group->tenant_type, $group->tenant_id, 'event', $name, $count);

            foreach ($context->featuresFor($name, self::str($group->ctx_route), self::str($group->ctx_component), self::str($group->ctx_action)) as $feature) {
                Rollups::add($rows, $group->tenant_type, $group->tenant_id, 'feature', $feature, $count);
            }
        }

        $this->rollups->clear('hour', $hour, self::KINDS);
        $this->rollups->insert('hour', $hour, $rows);
    }

    public function processDay(CarbonImmutable $day, AggregationContext $context): void
    {
        $this->rollups->rollDay($day, self::KINDS);
    }

    public static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_scalar($value) ? (string) $value : null);
    }
}
