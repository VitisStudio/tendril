<?php

namespace VitisStudio\Tendril\Marketing\Reports;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Marketing\Attribution\TouchpointIndex;
use VitisStudio\Tendril\Marketing\Conversions\ConversionDefinition;
use VitisStudio\Tendril\Marketing\Conversions\ConversionEvaluator;

/**
 * Ordered touchpoint paths leading to conversions, including the anonymous
 * part of journeys that became known through explicit alias links.
 */
final class ConversionPaths
{
    public function __construct(
        private readonly Database $db,
        private readonly ConversionEvaluator $evaluator,
    ) {}

    /**
     * @return array{paths: list<array{path: list<string>, conversions: int}>, anonymous_to_known: int, total: int}
     */
    public function top(ConversionDefinition $conversion, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, int $lookbackDays = 90, int $limit = 10): array
    {
        $records = $this->evaluator->records($conversion, $from, $to, $scope);
        $index = TouchpointIndex::build($records, $from, $to, $lookbackDays, $scope, $this->db);
        $paths = [];
        $anonymousToKnown = 0;

        foreach ($records as $record) {
            $touchpoints = $index->for($record, $lookbackDays);
            $labels = array_map(fn ($t) => ($t->source ?: '(direct)').' / '.($t->medium ?: '(none)'), $touchpoints);
            $labels = $labels === [] ? ['(direct) / (none)'] : $labels;
            $key = implode(' → ', $labels);
            $paths[$key] = ['path' => $labels, 'conversions' => ($paths[$key]['conversions'] ?? 0) + 1];

            if ($record->actor !== null && $index->hadAnonymousTouches($record, $lookbackDays)) {
                $anonymousToKnown++;
            }
        }

        uasort($paths, fn ($a, $b) => $b['conversions'] <=> $a['conversions']);

        return [
            'paths' => array_slice(array_values($paths), 0, $limit),
            'anonymous_to_known' => $anonymousToKnown,
            'total' => count($records),
        ];
    }
}
