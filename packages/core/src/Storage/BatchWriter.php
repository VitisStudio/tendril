<?php

namespace VitisStudio\Tendril\Core\Storage;

use Illuminate\Database\DetectsLostConnections;
use Illuminate\Support\Str;
use Throwable;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Events\BatchPersisted;
use VitisStudio\Tendril\Core\Identity\AliasWriter;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\FailOpen;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Persists a batch: idempotent multi-row event inserts, session upserts,
 * touchpoints and alias operations, then marks affected buckets dirty.
 *
 * Partial failures are isolated by bisecting the failing chunk; poison rows
 * are dead-lettered. Connectivity failures are rethrown so the transport can
 * retry (every write here is idempotent or monotonic).
 */
final class BatchWriter
{
    use DetectsLostConnections;

    public function __construct(
        private readonly Database $db,
        private readonly DirtyBuckets $dirty,
        private readonly DeadLetters $deadLetters,
        private readonly RowInserter $inserter,
        private readonly AliasWriter $aliases,
    ) {}

    public function write(Batch $batch, bool $deadLetterFailures = true): WriteResult
    {
        $result = new WriteResult;
        $rows = [];
        $instants = [];

        foreach ($batch->events as $event) {
            $row = $this->eventRow($event);

            if ($row === null) {
                $result->failed++;

                if ($deadLetterFailures) {
                    $this->deadLetters->storeRows($batch->id, [$event], 'invalid_event_row');
                }

                continue;
            }

            $rows[] = $row;
            $instants[] = Instant::parse($event['occurred_at']);
        }

        $chunkSize = max(1, (int) config('tendril.database.insert_chunk', 500));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $this->insertChunk($batch->id, $chunk, $result, $deadLetterFailures);
        }

        if ($instants !== []) {
            $this->dirty->mark($instants);
        }

        foreach ($batch->sessions as $op) {
            $this->guarded($batch->id, $op, 'session_op', $result, $deadLetterFailures, fn () => $this->applySession($op));
        }

        foreach ($batch->touchpoints as $touchpoint) {
            $this->guarded($batch->id, $touchpoint, 'touchpoint', $result, $deadLetterFailures, fn () => $this->insertTouchpoint($touchpoint));
        }

        foreach ($batch->aliases as $op) {
            $this->guarded($batch->id, $op, 'alias_op', $result, $deadLetterFailures, fn () => $this->aliases->apply($op));
        }

        $this->record($batch, $result);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunk(string $batchId, array $rows, WriteResult $result, bool $deadLetter): void
    {
        try {
            $inserted = $this->inserter->insert($rows);
            $result->inserted += $inserted;
            $result->duplicates += max(0, count($rows) - $inserted);
        } catch (Throwable $e) {
            $this->assertConnected($e);

            if (count($rows) === 1) {
                $result->failed++;

                if ($deadLetter) {
                    $this->deadLetters->storeRows($batchId, $rows, 'insert_failed: '.$e->getMessage());
                }

                return;
            }

            $half = intdiv(count($rows), 2);
            $this->insertChunk($batchId, array_slice($rows, 0, $half), $result, $deadLetter);
            $this->insertChunk($batchId, array_slice($rows, $half), $result, $deadLetter);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function guarded(string $batchId, array $payload, string $what, WriteResult $result, bool $deadLetter, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $e) {
            $this->assertConnected($e);
            $result->operationsFailed++;

            if ($deadLetter) {
                $this->deadLetters->storeRows($batchId, [['_operation' => $what] + $payload], "{$what}_failed: ".$e->getMessage());
            }
        }
    }

    /**
     * Rethrow when storage itself is unreachable; row-level errors are isolated.
     */
    private function assertConnected(Throwable $e): void
    {
        if ($this->causedByLostConnection($e)) {
            throw $e;
        }

        try {
            $this->db->connection()->select('select 1');
        } catch (Throwable) {
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function eventRow(array $event): ?array
    {
        try {
            $id = (string) ($event['id'] ?? '');
            $name = (string) ($event['name'] ?? '');

            if (strlen($id) !== 26 || $name === '') {
                return null;
            }

            // Arrays from capture, JSON strings from dead-letter retries.
            $properties = self::decoded($event['properties'] ?? null);
            $context = self::decoded($event['context'] ?? null);

            return [
                'id' => $id,
                'occurred_at' => $this->db->time(Instant::parse($event['occurred_at'])),
                'received_at' => $this->db->time(Instant::parse($event['received_at'] ?? $event['occurred_at'])),
                'name' => $name,
                'version' => (int) ($event['version'] ?? 1),
                'actor_type' => $event['actor_type'] ?? null,
                'actor_id' => $event['actor_id'] ?? null,
                'anonymous_id' => $event['anonymous_id'] ?? null,
                'session_id' => $event['session_id'] ?? null,
                'tenant_type' => $event['tenant_type'] ?? null,
                'tenant_id' => $event['tenant_id'] ?? null,
                'request_id' => $event['request_id'] ?? null,
                'trace_id' => $event['trace_id'] ?? null,
                'parent_id' => $event['parent_id'] ?? null,
                'dedupe_key' => (string) ($event['dedupe_key'] ?? $id),
                'source' => (string) ($event['source'] ?? 'app'),
                'environment' => (string) ($event['environment'] ?? 'production'),
                'properties' => $properties === [] ? null : self::json($properties),
                'context' => $context === [] ? null : self::json($context),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function applySession(array $op): void
    {
        $id = (string) ($op['id'] ?? '');

        if (strlen($id) !== 26) {
            return;
        }

        if (is_array($op['open'] ?? null)) {
            $open = $op['open'];
            $started = Instant::parse($open['started_at'] ?? Instant::now());

            $this->db->table(Tables::SESSIONS)->insertOrIgnore([
                'id' => $id,
                'anonymous_id' => $open['anonymous_id'] ?? null,
                'actor_type' => $open['actor_type'] ?? null,
                'actor_id' => $open['actor_id'] ?? null,
                'tenant_type' => $open['tenant_type'] ?? null,
                'tenant_id' => $open['tenant_id'] ?? null,
                'started_at' => $this->db->time($started),
                'last_seen_at' => $this->db->time($started),
                'landing_route' => $open['landing_route'] ?? null,
                'referrer_host' => $open['referrer_host'] ?? null,
                'referrer_class' => $open['referrer_class'] ?? null,
                'utm_source' => $open['utm_source'] ?? null,
                'utm_medium' => $open['utm_medium'] ?? null,
                'utm_campaign' => $open['utm_campaign'] ?? null,
                'utm_content' => $open['utm_content'] ?? null,
                'utm_term' => $open['utm_term'] ?? null,
                'click_ids' => is_array($open['click_ids'] ?? null) && $open['click_ids'] !== [] ? self::json($open['click_ids']) : null,
                'event_count' => 0,
                'page_view_count' => 0,
                'environment' => (string) ($open['environment'] ?? 'production'),
            ]);
        }

        $seen = Instant::tryParse($op['last_seen_at'] ?? null);

        if ($seen !== null) {
            $at = $this->db->time($seen);

            $this->db->table(Tables::SESSIONS)
                ->where('id', $id)
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $at))
                ->update(['last_seen_at' => $at]);
        }

        if (isset($op['actor_type'], $op['actor_id'])) {
            $this->db->table(Tables::SESSIONS)->where('id', $id)->update([
                'actor_type' => $op['actor_type'],
                'actor_id' => $op['actor_id'],
            ]);
        }

        if (isset($op['tenant_type'], $op['tenant_id'])) {
            $this->db->table(Tables::SESSIONS)->where('id', $id)->update([
                'tenant_type' => $op['tenant_type'],
                'tenant_id' => $op['tenant_id'],
            ]);
        }

        if (isset($op['landing_route'])) {
            $this->db->table(Tables::SESSIONS)->where('id', $id)->whereNull('landing_route')->update([
                'landing_route' => $op['landing_route'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertTouchpoint(array $row): void
    {
        $sessionId = (string) ($row['session_id'] ?? '');

        if (strlen($sessionId) !== 26) {
            return;
        }

        $this->db->table(Tables::TOUCHPOINTS)->insertOrIgnore(self::touchpointRow($row, $this->db));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function touchpointRow(array $row, Database $db): array
    {
        $clickIds = is_array($row['click_ids'] ?? null) ? $row['click_ids'] : [];

        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : (string) Str::ulid(),
            'session_id' => (string) $row['session_id'],
            'occurred_at' => $db->time(Instant::parse($row['occurred_at'] ?? Instant::now())),
            'anonymous_id' => $row['anonymous_id'] ?? null,
            'actor_type' => $row['actor_type'] ?? null,
            'actor_id' => $row['actor_id'] ?? null,
            'tenant_type' => $row['tenant_type'] ?? null,
            'tenant_id' => $row['tenant_id'] ?? null,
            'source' => $row['source'] ?? null,
            'medium' => $row['medium'] ?? null,
            'campaign' => $row['campaign'] ?? null,
            'content' => $row['content'] ?? null,
            'term' => $row['term'] ?? null,
            'referrer_host' => $row['referrer_host'] ?? null,
            'referrer_class' => $row['referrer_class'] ?? null,
            'landing_route' => $row['landing_route'] ?? null,
            'has_click_id' => $clickIds !== [],
            'click_ids' => $clickIds === [] ? null : self::json($clickIds),
            'environment' => (string) ($row['environment'] ?? 'production'),
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<mixed>  $value
     */
    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function record(Batch $batch, WriteResult $result): void
    {
        $stats = app(IngestionStats::class);
        $stats->increment('persisted', $result->inserted);
        $stats->increment('duplicates', $result->duplicates);
        $stats->increment('batches');

        $created = Instant::tryParse($batch->createdAt);

        if ($created !== null) {
            $lag = max(0, (int) round(($created->diffInMilliseconds(Instant::now(), true))));
            $stats->increment('lag_ms_total', $lag);
            $stats->max('lag_ms_max', $lag);
        }

        FailOpen::dispatch(new BatchPersisted($batch->id, $result->inserted, $result->duplicates, $result->failed));
    }
}
