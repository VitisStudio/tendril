<?php

namespace VitisStudio\Tendril\Destinations;

use Throwable;
use VitisStudio\Tendril\Core\Query\TenantScope;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;
use VitisStudio\Tendril\Marketing\Conversions\ConversionEvaluator;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRecord;
use VitisStudio\Tendril\Marketing\Conversions\ConversionRepository;

/**
 * Finds settled conversions in the lookback window, skips ones already
 * delivered/skipped or out of attempts, attaches session click IDs, sends in
 * batches and records one delivery row per (destination, event). Safe to run
 * concurrently with itself only if the scheduler uses withoutOverlapping().
 */
final class DeliveryDispatcher
{
    public function __construct(
        private readonly Database $db,
        private readonly DestinationManager $destinations,
        private readonly ConversionRepository $conversions,
        private readonly ConversionEvaluator $evaluator,
    ) {}

    /**
     * @return array<string, array{delivered: int, failed: int, skipped: int}>
     */
    public function dispatch(?string $only = null): array
    {
        $summary = [];

        foreach (array_keys($this->destinations->configured()) as $name) {
            if ($only !== null && $only !== $name) {
                continue;
            }

            $summary[$name] = $this->dispatchTo($name);
        }

        return $summary;
    }

    /**
     * @return array{delivered: int, failed: int, skipped: int}
     */
    public function dispatchTo(string $name): array
    {
        $counts = ['delivered' => 0, 'failed' => 0, 'skipped' => 0];
        $destination = $this->destinations->make($name);
        $mapping = $this->destinations->mapping($name);
        $definitions = $this->conversions->all();
        $to = Instant::now()->subMinutes(max(0, (int) config('tendril-destinations.settle_minutes', 5)));
        $from = Instant::now()->subHours(max(1, (int) config('tendril-destinations.lookback_hours', 72)));
        $maxAttempts = max(1, (int) config('tendril-destinations.max_attempts', 5));
        $batchSize = max(1, (int) config('tendril-destinations.batch_size', 100));

        $pending = [];

        foreach ($mapping as $key => $destinationEvent) {
            if (! isset($definitions[$key])) {
                continue;
            }

            foreach ($this->evaluator->records($definitions[$key], $from, $to, TenantScope::all()) as $record) {
                $pending[$record->eventId] = [$record, $destinationEvent];
            }
        }

        if ($pending === []) {
            return $counts;
        }

        $done = $this->finished($name, array_keys($pending), $maxAttempts);
        $pending = array_diff_key($pending, $done);
        $clickIds = $this->clickIds(array_map(fn (array $p) => $p[0], $pending));
        $outbound = [];

        foreach ($pending as [$record, $destinationEvent]) {
            $conversion = new OutboundConversion($record, $destinationEvent, $clickIds[(string) $record->sessionId] ?? []);
            $accepts = $destination->accepts($conversion);

            if ($accepts !== true) {
                $this->record($name, $record, DeliveryResult::skipped($record->eventId, $accepts));
                $counts['skipped']++;

                continue;
            }

            $outbound[] = $conversion;
        }

        foreach (array_chunk($outbound, $batchSize) as $batch) {
            try {
                $results = $destination->send($batch);
            } catch (Throwable $e) {
                $results = array_map(fn (OutboundConversion $c) => DeliveryResult::failed($c->eventId(), $e->getMessage()), $batch);
            }

            $byId = [];

            foreach ($results as $result) {
                $byId[$result->eventId] = $result;
            }

            foreach ($batch as $conversion) {
                $result = $byId[$conversion->eventId()] ?? DeliveryResult::failed($conversion->eventId(), 'no result returned by destination');
                $this->record($name, $conversion->record, $result);
                $counts[$result->status]++;
            }
        }

        return ['delivered' => $counts['delivered'], 'failed' => $counts['failed'], 'skipped' => $counts['skipped']];
    }

    /**
     * @param  list<string>  $eventIds
     * @return array<string, true>
     */
    private function finished(string $destination, array $eventIds, int $maxAttempts): array
    {
        $done = [];

        foreach (array_chunk($eventIds, 500) as $chunk) {
            $rows = $this->db->table(Tables::DELIVERIES)
                ->where('destination', $destination)
                ->whereIn('event_id', $chunk)
                ->where(fn ($q) => $q->whereIn('status', [DeliveryResult::DELIVERED, DeliveryResult::SKIPPED])->orWhere('attempts', '>=', $maxAttempts))
                ->pluck('event_id');

            foreach ($rows as $id) {
                $done[(string) $id] = true;
            }
        }

        return $done;
    }

    /**
     * @param  array<string, ConversionRecord>  $records
     * @return array<string, array<string, string>> session id => click ids
     */
    private function clickIds(array $records): array
    {
        $sessions = array_values(array_unique(array_filter(array_map(fn (ConversionRecord $r) => $r->sessionId, $records))));
        $out = [];

        foreach (array_chunk($sessions, 500) as $chunk) {
            foreach ($this->db->table(Tables::SESSIONS)->whereIn('id', $chunk)->whereNotNull('click_ids')->get(['id', 'click_ids']) as $row) {
                $decoded = is_string($row->click_ids) ? json_decode($row->click_ids, true) : null;

                if (is_array($decoded)) {
                    $ids = [];

                    foreach ($decoded as $key => $value) {
                        if (is_string($key) && is_string($value)) {
                            $ids[$key] = $value;
                        }
                    }

                    $out[(string) $row->id] = $ids;
                }
            }
        }

        return $out;
    }

    private function record(string $destination, ConversionRecord $record, DeliveryResult $result): void
    {
        $now = $this->db->time(Instant::now());
        $existing = $this->db->table(Tables::DELIVERIES)->where('destination', $destination)->where('event_id', $record->eventId)->first();

        $values = [
            'status' => $result->status,
            'last_error' => $result->error,
            'response_code' => $result->responseCode,
            'delivered_at' => $result->status === DeliveryResult::DELIVERED ? $now : null,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $this->db->table(Tables::DELIVERIES)->insert($values + [
                'destination' => $destination,
                'conversion_key' => $record->conversionKey,
                'event_id' => $record->eventId,
                'attempts' => $result->status === DeliveryResult::SKIPPED ? 0 : 1,
                'occurred_at' => $this->db->time($record->occurredAt),
            ]);

            return;
        }

        $this->db->table(Tables::DELIVERIES)->where('id', $existing->id)->update($values + [
            'attempts' => (int) $existing->attempts + ($result->status === DeliveryResult::SKIPPED ? 0 : 1),
        ]);
    }
}
