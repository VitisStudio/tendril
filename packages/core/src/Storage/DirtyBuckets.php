<?php

namespace VitisStudio\Tendril\Core\Storage;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Hour buckets whose derived data (rollups, activity) must be recomputed.
 *
 * Writers mark the hours of every inserted event (late events reopen old
 * buckets). Aggregators claim marks, recompute from raw facts and complete
 * a mark only if it was not re-marked in the meantime.
 */
final class DirtyBuckets
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param  iterable<DateTimeInterface>  $instants
     */
    public function mark(iterable $instants, string $reason = 'ingest'): int
    {
        $hours = [];

        foreach ($instants as $instant) {
            $hour = Instant::hour($instant);
            $hours[$hour->getTimestamp()] = $hour;
        }

        if ($hours === []) {
            return 0;
        }

        $now = $this->db->time(Instant::now());
        $rows = [];

        foreach ($hours as $hour) {
            $rows[] = [
                'bucket_start' => $this->db->time($hour),
                'reason' => substr($reason, 0, 32),
                'marked_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table(Tables::DIRTY_BUCKETS)->upsert($chunk, ['bucket_start'], ['marked_at', 'reason']);
        }

        return count($rows);
    }

    /**
     * Mark every hour in [from, to).
     */
    public function markRange(DateTimeInterface $from, DateTimeInterface $to, string $reason = 'rebuild'): int
    {
        $cursor = Instant::hour($from);
        $end = CarbonImmutable::instance($to)->utc();
        $batch = [];
        $count = 0;

        while ($cursor->lessThan($end)) {
            $batch[] = $cursor;
            $cursor = $cursor->addHour();

            if (count($batch) >= 500) {
                $count += $this->mark($batch, $reason);
                $batch = [];
            }
        }

        return $count + $this->mark($batch, $reason);
    }

    /**
     * Oldest marks first.
     *
     * @return list<array{bucket_start: CarbonImmutable, marked_at: string}>
     */
    public function claim(int $limit): array
    {
        $rows = $this->db->table(Tables::DIRTY_BUCKETS)
            ->orderBy('bucket_start')
            ->limit(max(1, $limit))
            ->get(['bucket_start', 'marked_at']);

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'bucket_start' => Instant::parse($row->bucket_start),
                'marked_at' => (string) $row->marked_at,
            ];
        }

        return $out;
    }

    /**
     * @param  array{bucket_start: CarbonImmutable, marked_at: string}  $mark
     */
    public function complete(array $mark): void
    {
        $this->db->table(Tables::DIRTY_BUCKETS)
            ->where('bucket_start', $this->db->time($mark['bucket_start']))
            ->where('marked_at', '<=', $this->db->time(Instant::parse($mark['marked_at'])))
            ->delete();
    }

    public function discardBefore(DateTimeInterface $cutoff): int
    {
        return $this->db->table(Tables::DIRTY_BUCKETS)
            ->where('bucket_start', '<', $this->db->time($cutoff))
            ->delete();
    }

    public function count(): int
    {
        return $this->db->table(Tables::DIRTY_BUCKETS)->count();
    }

    public function oldestMarkedAt(): ?CarbonImmutable
    {
        return Instant::tryParse($this->db->table(Tables::DIRTY_BUCKETS)->min('marked_at'));
    }
}
