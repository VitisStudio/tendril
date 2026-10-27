<?php

namespace VitisStudio\Tendril\Core\Storage;

use DateTimeInterface;
use Illuminate\Support\Str;
use Throwable;
use VitisStudio\Tendril\Core\Capture\Batch;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Visible, bounded storage for batches/rows that could not be persisted.
 * Writing a dead letter is itself best effort.
 */
final class DeadLetters
{
    public const int MAX_PAYLOAD_BYTES = 1048576;

    public function __construct(private readonly Database $db) {}

    public function storeBatch(Batch $batch, string $reason, int $attempts = 1): bool
    {
        return $this->store($batch->id, json_encode($batch->toArray()) ?: '{}', $reason, $attempts, $batch->eventCount());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function storeRows(string $batchId, array $rows, string $reason): bool
    {
        return $this->store($batchId, json_encode(['rows' => $rows]) ?: '{}', $reason, 1, count($rows));
    }

    public function storeRaw(string $payload, string $reason): bool
    {
        return $this->store(null, json_encode(['raw' => mb_substr($payload, 0, self::MAX_PAYLOAD_BYTES)]) ?: '{}', $reason, 1, 0);
    }

    public function count(): int
    {
        return $this->db->table(Tables::DEAD_LETTERS)->count();
    }

    /**
     * Retry dead letters that contain batches or rows. Returns [retried, failed].
     *
     * @return array{0: int, 1: int}
     */
    public function retry(int $limit = 100): array
    {
        $retried = 0;
        $failed = 0;
        $writer = app(BatchWriter::class);

        foreach ($this->db->table(Tables::DEAD_LETTERS)->orderBy('failed_at')->limit($limit)->get() as $row) {
            $payload = json_decode((string) $row->payload, true);

            if (! is_array($payload) || isset($payload['raw']) || isset($payload['truncated'])) {
                $failed++;

                continue;
            }

            try {
                $batch = isset($payload['rows']) && is_array($payload['rows'])
                    ? Batch::make(array_values(array_filter($payload['rows'], 'is_array')))
                    : Batch::fromArray($payload);

                $result = $writer->write($batch, deadLetterFailures: false);

                if ($result->failed > 0) {
                    $failed++;

                    continue;
                }

                $this->db->table(Tables::DEAD_LETTERS)->where('id', $row->id)->delete();
                $retried++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [$retried, $failed];
    }

    public function purgeBefore(DateTimeInterface $cutoff): int
    {
        return $this->db->table(Tables::DEAD_LETTERS)->where('failed_at', '<', $this->db->time($cutoff))->delete();
    }

    private function store(?string $batchId, string $payload, string $reason, int $attempts, int $events): bool
    {
        try {
            if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                $payload = json_encode(['truncated' => true, 'bytes' => strlen($payload)]) ?: '{}';
            }

            $this->db->table(Tables::DEAD_LETTERS)->insert([
                'id' => (string) Str::ulid(),
                'batch_id' => $batchId,
                'reason' => mb_substr($reason, 0, 1000),
                'payload' => $payload,
                'events' => $events,
                'attempts' => $attempts,
                'failed_at' => $this->db->time(Instant::now()),
            ]);

            app(IngestionStats::class)->increment('dead_lettered', max(1, $events));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
