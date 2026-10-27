<?php

namespace VitisStudio\Tendril\Core\Models\Concerns;

/**
 * Models live on the configurable Tendril connection.
 */
trait UsesTendrilConnection
{
    public function getConnectionName(): ?string
    {
        $connection = config('tendril.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }
}
