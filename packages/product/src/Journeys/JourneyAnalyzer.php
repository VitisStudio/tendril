<?php

namespace VitisStudio\Tendril\Product\Journeys;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Journeys: the most common paths of events following a starting event within
 * the same analytics session. Bounded by a session sample so it never scans
 * the firehose; page views are labelled by route for readability.
 */
final class JourneyAnalyzer
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array{path: list<string>, sessions: int}>
     */
    public function next(string $startEvent, CarbonImmutable $from, CarbonImmutable $to, TenantScope $scope, int $depth = 3, int $limit = 10, int $maxSessions = 2000): array
    {
        $depth = max(1, min(10, $depth));

        $sessions = $scope->applyToRaw($this->db->table(Tables::EVENTS))
            ->where('name', strtolower($startEvent))
            ->where('occurred_at', '>=', $this->db->time($from))
            ->where('occurred_at', '<', $this->db->time($to))
            ->whereNotNull('session_id')
            ->distinct()
            ->limit($maxSessions)
            ->pluck('session_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $paths = [];
        $route = $this->db->json()->text('context', ['route']);

        foreach (array_chunk($sessions, 200) as $chunk) {
            $rows = $this->db->table(Tables::EVENTS)
                ->select(['session_id', 'name', 'occurred_at', 'id'])
                ->selectRaw("{$route} as ctx_route")
                ->whereIn('session_id', $chunk)
                ->orderBy('session_id')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->groupBy('session_id');

            foreach ($rows as $events) {
                $labels = $events->map(fn ($e) => $e->name === 'page.viewed' && is_string($e->ctx_route) ? 'page:'.$e->ctx_route : (string) $e->name)->values()->all();
                $start = array_search(strtolower($startEvent), $events->pluck('name')->all(), true);

                if ($start === false) {
                    continue;
                }

                $path = array_slice($labels, (int) $start + 1, $depth);

                if ($path === []) {
                    $path = ['(exit)'];
                }

                $key = implode(' → ', $path);
                $paths[$key] = ['path' => $path, 'sessions' => ($paths[$key]['sessions'] ?? 0) + 1];
            }
        }

        uasort($paths, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);

        return array_slice(array_values($paths), 0, $limit);
    }
}
