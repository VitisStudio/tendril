<?php

namespace VitisStudio\Tendril\Core\Storage;

use Carbon\CarbonImmutable;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Instant;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Exports raw events day by day to NDJSON (gzip) on a filesystem disk before
 * pruning. Deterministic paths make re-runs idempotent (a day is rewritten).
 */
final class Archiver
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return int events written
     */
    public function archiveDay(CarbonImmutable $day, int $chunk = 5000): int
    {
        $day = Instant::day($day);
        $disk = app('filesystem')->disk((string) config('tendril.retention.archive.disk', 'local'));
        $path = $this->path($day);
        $lines = [];
        $count = 0;
        $lastId = null;

        do {
            $query = $this->db->table(Tables::EVENTS)
                ->where('occurred_at', '>=', $this->db->time($day))
                ->where('occurred_at', '<', $this->db->time($day->addDay()))
                ->orderBy('id')
                ->limit($chunk);

            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $data = (array) $row;
                $data['occurred_at'] = Instant::serialize(Instant::parse($data['occurred_at']));
                $data['received_at'] = Instant::serialize(Instant::parse($data['received_at']));
                $data['properties'] = is_string($data['properties'] ?? null) ? json_decode($data['properties'], true) : null;
                $data['context'] = is_string($data['context'] ?? null) ? json_decode($data['context'], true) : null;
                $lines[] = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $lastId = (string) $row->id;
                $count++;
            }
        } while ($rows->count() === $chunk);

        if ($count > 0) {
            $encoded = gzencode(implode("\n", $lines)."\n", 6);
            $disk->put($path, $encoded === false ? implode("\n", $lines) : $encoded);
        }

        return $count;
    }

    public function path(CarbonImmutable $day): string
    {
        return rtrim((string) config('tendril.retention.archive.path', 'tendril/archive'), '/')
            .'/events/'.$day->format('Y/m/d').'.ndjson.gz';
    }
}
