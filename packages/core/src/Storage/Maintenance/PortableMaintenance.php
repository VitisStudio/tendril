<?php

namespace VitisStudio\Tendril\Core\Storage\Maintenance;

use Carbon\CarbonImmutable;
use Throwable;
use VitisStudio\Tendril\Core\Contracts\MaintenanceAdapter;
use VitisStudio\Tendril\Core\Support\Database;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Works everywhere: chunked DELETEs by primary key, oldest first. Each chunk
 * is its own short statement, so pruning never holds long locks and can be
 * interrupted and resumed at any time.
 */
class PortableMaintenance implements MaintenanceAdapter
{
    public function __construct(protected readonly Database $db) {}

    public function name(): string
    {
        return 'portable';
    }

    public function capabilities(): array
    {
        return [
            'driver' => $this->db->driver(),
            'partitioned' => false,
            'partition_drop' => false,
            'chunked_delete' => true,
        ];
    }

    public function prepare(CarbonImmutable $until): array
    {
        return [];
    }

    public function pruneBefore(CarbonImmutable $cutoff, int $chunk, ?callable $progress = null): int
    {
        $total = 0;
        $chunk = max(1, $chunk);
        $bound = $this->db->time($cutoff);

        while (true) {
            $ids = $this->db->table(Tables::EVENTS)
                ->where('occurred_at', '<', $bound)
                ->orderBy('occurred_at')
                ->limit($chunk)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $total += $this->db->table(Tables::EVENTS)->whereIn('id', $ids)->delete();

            if ($progress !== null) {
                $progress($total);
            }

            if (count($ids) < $chunk) {
                break;
            }
        }

        return $total;
    }

    public function describe(): array
    {
        return [
            'adapter' => $this->name(),
            'tables' => $this->tableSizes(),
        ];
    }

    /**
     * @return array<string, int|null> approximate bytes per table (null when unknown)
     */
    protected function tableSizes(): array
    {
        $sizes = [];
        $connection = $this->db->connection();

        foreach (Tables::core() as $table) {
            try {
                $sizes[$table] = match ($this->db->driver()) {
                    'pgsql' => (int) ($connection->selectOne('SELECT pg_total_relation_size(to_regclass(?)) AS bytes', [$table])->bytes ?? 0),
                    'mysql' => (int) ($connection->selectOne(
                        'SELECT (DATA_LENGTH + INDEX_LENGTH) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                        [$table],
                    )->bytes ?? 0),
                    default => null,
                };
            } catch (Throwable) {
                $sizes[$table] = null;
            }
        }

        return $sizes;
    }
}
