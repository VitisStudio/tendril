<?php

namespace VitisStudio\Tendril\Marketing\Reports;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Marketing\Attribution\AttributionEngine;
use VitisStudio\Tendril\Marketing\Attribution\AttributionReport;
use VitisStudio\Tendril\Marketing\Conversions\ConversionDefinition;

/**
 * Source / medium / campaign reporting: arrivals (touchpoint sessions),
 * landing pages, and attributed conversions and value (model + window stamped).
 */
final class CampaignReport
{
    public function __construct(
        private readonly Database $db,
        private readonly AttributionEngine $attribution,
    ) {}

    /**
     * @param  list<ConversionDefinition>  $conversions
     * @return array{attribution: AttributionReport, rows: list<array{source: string, medium: string, campaign: string, sessions: int, conversions: float, values: array<string, int>}>}
     */
    public function campaigns(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, array $conversions, ?string $model = null, ?int $lookbackDays = null): array
    {
        $sessions = $scope->applyToRaw($this->db->table(Tables::TOUCHPOINTS))
            ->where('occurred_at', '>=', $this->db->time($from))
            ->where('occurred_at', '<', $this->db->time($to))
            ->select(['source', 'medium', 'campaign'])
            ->selectRaw('count(*) as sessions')
            ->groupBy(['source', 'medium', 'campaign'])
            ->get();

        $rows = [];

        foreach ($sessions as $row) {
            $key = $this->key($row->source, $row->medium, $row->campaign);
            $rows[$key] = [
                'source' => $this->label($row->source, AttributionEngine::DIRECT),
                'medium' => $this->label($row->medium, AttributionEngine::NONE),
                'campaign' => $this->label($row->campaign, AttributionEngine::NONE),
                'sessions' => (int) $row->sessions,
                'conversions' => 0.0,
                'values' => [],
            ];
        }

        $report = $this->attribution->report($conversions, $from, $to, $scope, $model, $lookbackDays);

        foreach ($report->rows as $row) {
            $key = $this->key($row['source'], $row['medium'], $row['campaign']);
            $rows[$key] ??= ['source' => $row['source'], 'medium' => $row['medium'], 'campaign' => $row['campaign'], 'sessions' => 0, 'conversions' => 0.0, 'values' => []];
            $rows[$key]['conversions'] = $row['conversions'];
            $rows[$key]['values'] = $row['values'];
        }

        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => [$b['sessions'], $b['conversions']] <=> [$a['sessions'], $a['conversions']]);

        return ['attribution' => $report, 'rows' => $rows];
    }

    /**
     * @return list<array{landing_route: string, sessions: int}>
     */
    public function landingPages(CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, int $limit = 20): array
    {
        return array_values($scope->applyToRaw($this->db->table(Tables::TOUCHPOINTS))
            ->where('occurred_at', '>=', $this->db->time($from))
            ->where('occurred_at', '<', $this->db->time($to))
            ->select('landing_route')
            ->selectRaw('count(*) as sessions')
            ->groupBy('landing_route')
            ->orderByDesc('sessions')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['landing_route' => (string) ($r->landing_route ?? '(unknown)'), 'sessions' => (int) $r->sessions])
            ->all());
    }

    private function key(mixed $source, mixed $medium, mixed $campaign): string
    {
        return $this->label($source, AttributionEngine::DIRECT)."\0".$this->label($medium, AttributionEngine::NONE)."\0".$this->label($campaign, AttributionEngine::NONE);
    }

    private function label(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
