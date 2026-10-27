<?php

namespace VitisStudio\Tendril\Marketing\Aggregation;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Marketing\Conversions\ConversionDefinition;
use VitisStudio\Tendril\Marketing\Conversions\ConversionEvaluator;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;
use VitisStudio\Tendril\Product\Aggregation\AggregationContext;
use VitisStudio\Tendril\Product\Aggregation\BucketProcessor;
use VitisStudio\Tendril\Product\Aggregation\Rollups;

/**
 * Conversion rollups per hour/day: dimension "{key}" carries the count,
 * "{key}:{CUR}" carries count and summed value in minor units per currency.
 * Daily activity rows of kind "conversion" give exact unique converters.
 */
final class ConversionProcessor implements BucketProcessor
{
    /** @var array<string, ConversionDefinition>|null */
    private ?array $conversions = null;

    public function __construct(
        private readonly Rollups $rollups,
        private readonly ConversionRepository $repository,
        private readonly ConversionEvaluator $evaluator,
    ) {}

    public function key(): string
    {
        return 'conversions';
    }

    public function processHour(CarbonImmutable $hour, AggregationContext $context): void
    {
        $rows = [];

        foreach ($this->conversions() as $conversion) {
            foreach ($this->evaluator->records($conversion, $hour, $hour->addHour(), TenantScope::all()) as $record) {
                Rollups::add($rows, $record->tenantType, $record->tenantId, 'conversion', $conversion->key, 1);

                if ($record->valueMinor !== null && $record->currency !== null) {
                    Rollups::add($rows, $record->tenantType, $record->tenantId, 'conversion', $conversion->key.':'.$record->currency, 1, $record->valueMinor);
                }
            }
        }

        $this->rollups->clear('hour', $hour, ['conversion']);
        $this->rollups->insert('hour', $hour, $rows);
    }

    public function processDay(CarbonImmutable $day, AggregationContext $context): void
    {
        $this->rollups->rollDay($day, ['conversion']);

        $rows = [];

        foreach ($this->conversions() as $conversion) {
            foreach ($this->evaluator->records($conversion, $day, $day->addDay(), TenantScope::all()) as $record) {
                $subject = $record->subject();

                if ($subject === null) {
                    continue;
                }

                $key = implode("\0", [(string) $record->tenantType, (string) $record->tenantId, $conversion->key, $subject->type, $subject->id]);
                $rows[$key] ??= [
                    'date' => $day->toDateString(),
                    'tenant_type' => (string) $record->tenantType,
                    'tenant_id' => (string) $record->tenantId,
                    'actor_type' => $subject->type,
                    'actor_id' => $subject->id,
                    'kind' => 'conversion',
                    'subject' => $conversion->key,
                    'event_count' => 0,
                ];
                $rows[$key]['event_count']++;
            }
        }

        $db = $context->db;
        $db->table(Tables::ACTIVITY)->where('date', $day->toDateString())->where('kind', 'conversion')->delete();

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            $db->table(Tables::ACTIVITY)->insert($chunk);
        }
    }

    /**
     * @return array<string, ConversionDefinition>
     */
    private function conversions(): array
    {
        return $this->conversions ??= $this->repository->all();
    }
}
