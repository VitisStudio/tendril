<?php

namespace VitisStudio\Tendril\Product\Aggregation\Processors;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Product\Aggregation\AggregationContext;
use VitisStudio\Tendril\Product\Aggregation\BucketProcessor;
use VitisStudio\Tendril\Product\Aggregation\Rollups;

/**
 * Session counters (event_count, page_view_count) recomputed from raw events
 * for every session with events in a dirty hour (exact and idempotent, unlike
 * incrementing on ingest), then daily session rollups by start day:
 * started (sum = total duration seconds) and bounced (<= 1 page view).
 */
final class SessionProcessor implements BucketProcessor
{
    public function __construct(private readonly Rollups $rollups) {}

    public function key(): string
    {
        return 'sessions';
    }

    public function processHour(CarbonImmutable $hour, AggregationContext $context): void
    {
        $db = $context->db;

        $sessionIds = $db->table(Tables::EVENTS)
            ->where('occurred_at', '>=', $db->time($hour))
            ->where('occurred_at', '<', $db->time($hour->addHour()))
            ->whereNotNull('session_id')
            ->distinct()
            ->pluck('session_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        foreach (array_chunk($sessionIds, 500) as $chunk) {
            $stats = $db->table(Tables::EVENTS)
                ->select('session_id')
                ->selectRaw('count(*) as events, sum(case when '.$db->wrap('name').' = ? then 1 else 0 end) as page_views', [$context->pageViewEvent])
                ->whereIn('session_id', $chunk)
                ->groupBy('session_id')
                ->get();

            foreach ($stats as $row) {
                $db->table(Tables::SESSIONS)->where('id', $row->session_id)->update([
                    'event_count' => (int) $row->events,
                    'page_view_count' => (int) $row->page_views,
                ]);
            }

            foreach ($db->table(Tables::SESSIONS)->whereIn('id', $chunk)->pluck('started_at') as $started) {
                $day = Instant::day(Instant::parse($started));
                $context->sessionDays[$day->getTimestamp()] = $day;
            }
        }
    }

    public function processDay(CarbonImmutable $day, AggregationContext $context): void
    {
        $days = [$day->getTimestamp() => $day] + $context->sessionDays;
        $context->sessionDays = [];

        foreach ($days as $d) {
            $this->rollSessionDay($d, $context);
        }
    }

    private function rollSessionDay(CarbonImmutable $day, AggregationContext $context): void
    {
        $db = $context->db;
        $rows = [];

        $sessions = $db->table(Tables::SESSIONS)
            ->where('started_at', '>=', $db->time($day))
            ->where('started_at', '<', $db->time($day->addDay()))
            ->orderBy('id')
            ->select(['tenant_type', 'tenant_id', 'started_at', 'last_seen_at', 'page_view_count'])
            ->cursor();

        foreach ($sessions as $session) {
            $started = Instant::parse($session->started_at);
            $seen = Instant::tryParse($session->last_seen_at) ?? $started;
            $duration = max(0, $seen->getTimestamp() - $started->getTimestamp());

            Rollups::add($rows, $session->tenant_type, $session->tenant_id, 'session', 'started', 1, $duration);

            if ((int) $session->page_view_count <= 1) {
                Rollups::add($rows, $session->tenant_type, $session->tenant_id, 'session', 'bounced', 1);
            }
        }

        $this->rollups->clear('day', $day, ['session']);
        $this->rollups->insert('day', $day, $rows);
    }
}
