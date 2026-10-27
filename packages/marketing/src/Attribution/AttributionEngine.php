<?php

namespace VitisStudio\Tendril\Marketing\Attribution;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Marketing\Conversions\ConversionDefinition;
use VitisStudio\Tendril\Marketing\Conversions\ConversionEvaluator;
use VitisStudio\Tendril\Marketing\Money\Money;

/**
 * Query-time attribution over touchpoints. Conversions without a touchpoint
 * in the lookback window are reported as (direct) / (none).
 */
final class AttributionEngine
{
    public const string DIRECT = '(direct)';

    public const string NONE = '(none)';

    public function __construct(
        private readonly Database $db,
        private readonly ConversionEvaluator $evaluator,
    ) {}

    /**
     * @param  list<ConversionDefinition>  $conversions
     */
    public function report(array $conversions, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, ?string $model = null, ?int $lookbackDays = null): AttributionReport
    {
        $model = Models::make($model ?? (string) config('tendril-marketing.attribution.default_model', 'last_touch'));
        $lookbackDays = max(1, $lookbackDays ?? (int) config('tendril-marketing.attribution.lookback_days', 90));

        $records = [];

        foreach ($conversions as $conversion) {
            $records = [...$records, ...$this->evaluator->records($conversion, $from, $to, $scope)];
        }

        $index = TouchpointIndex::build($records, $from, $to, $lookbackDays, $scope, $this->db);
        $rows = [];
        $totals = [];

        foreach ($records as $record) {
            $touchpoints = $index->for($record, $lookbackDays);

            if ($record->valueMinor !== null && $record->currency !== null) {
                $totals[$record->currency] = ($totals[$record->currency] ?? 0) + $record->valueMinor;
            }

            if ($touchpoints === []) {
                $this->credit($rows, self::DIRECT, self::NONE, self::NONE, 1.0, $record->currency, $record->valueMinor);

                continue;
            }

            $weights = $model->weights(count($touchpoints));
            $values = $record->valueMinor !== null ? Money::allocate($record->valueMinor, $weights) : array_fill(0, count($weights), null);

            foreach ($touchpoints as $i => $touchpoint) {
                if ($weights[$i] <= 0.0 && ($values[$i] ?? 0) === 0) {
                    continue;
                }

                $this->credit(
                    $rows,
                    is_string($touchpoint->source) && $touchpoint->source !== '' ? $touchpoint->source : self::DIRECT,
                    is_string($touchpoint->medium) && $touchpoint->medium !== '' ? $touchpoint->medium : self::NONE,
                    is_string($touchpoint->campaign) && $touchpoint->campaign !== '' ? $touchpoint->campaign : self::NONE,
                    $weights[$i],
                    $record->currency,
                    $values[$i],
                );
            }
        }

        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['conversions'], $a['source'], $a['medium'], $a['campaign']] <=> [$a['conversions'], $b['source'], $b['medium'], $b['campaign']]);

        foreach ($rows as &$row) {
            $row['conversions'] = round($row['conversions'], 4);
            ksort($row['values']);
        }
        unset($row);

        ksort($totals);

        return new AttributionReport(
            $model->name(),
            $model->label(),
            $lookbackDays,
            Instant::serialize($from),
            Instant::serialize($to),
            array_map(fn (ConversionDefinition $c) => $c->key, $conversions),
            $rows,
            count($records),
            $totals,
        );
    }

    /**
     * @param  array<string, array{source: string, medium: string, campaign: string, conversions: float, values: array<string, int>}>  $rows
     */
    private function credit(array &$rows, string $source, string $medium, string $campaign, float $weight, ?string $currency, ?int $value): void
    {
        $key = $source."\0".$medium."\0".$campaign;
        $rows[$key] ??= ['source' => $source, 'medium' => $medium, 'campaign' => $campaign, 'conversions' => 0.0, 'values' => []];
        $rows[$key]['conversions'] += $weight;

        if ($currency !== null && $value !== null) {
            $rows[$key]['values'][$currency] = ($rows[$key]['values'][$currency] ?? 0) + $value;
        }
    }
}
