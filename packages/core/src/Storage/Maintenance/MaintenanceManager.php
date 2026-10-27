<?php

namespace VitisStudio\Tendril\Core\Storage\Maintenance;

use Closure;
use InvalidArgumentException;
use VitisStudio\Tendril\Core\Contracts\MaintenanceAdapter;
use VitisStudio\Tendril\Core\Support\Database;

/**
 * Picks the maintenance adapter: explicit config, or "auto" (native partition
 * strategy when the events table is partitioned, portable otherwise).
 * Other packages (tendril-timescale) register adapters via extend().
 */
final class MaintenanceManager
{
    /** @var array<string, Closure(): MaintenanceAdapter> */
    private array $adapters = [];

    /**
     * @param  Closure(): MaintenanceAdapter  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->adapters[$name] = $factory;
    }

    public function adapter(?string $name = null): MaintenanceAdapter
    {
        $name ??= (string) config('tendril.storage.adapter', 'auto');
        $db = app(Database::class);

        if (isset($this->adapters[$name])) {
            return ($this->adapters[$name])();
        }

        return match ($name) {
            'portable' => new PortableMaintenance($db),
            'pgsql-partitions' => new PostgresPartitionMaintenance($db),
            'mysql-partitions' => new MySqlPartitionMaintenance($db),
            'auto' => $this->auto($db),
            default => throw new InvalidArgumentException("Unknown Tendril storage adapter [{$name}]."),
        };
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_unique(['portable', 'pgsql-partitions', 'mysql-partitions', ...array_keys($this->adapters)]));
    }

    private function auto(Database $db): MaintenanceAdapter
    {
        return match ($db->driver()) {
            'pgsql' => ($pg = new PostgresPartitionMaintenance($db))->isPartitioned() ? $pg : new PortableMaintenance($db),
            'mysql' => ($my = new MySqlPartitionMaintenance($db))->isPartitioned() ? $my : new PortableMaintenance($db),
            default => new PortableMaintenance($db),
        };
    }
}
