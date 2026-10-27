<?php

namespace VitisStudio\Tendril\Core\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Base for every Tendril migration: honors tendril.database.connection and
 * provides the portable instant column (timestamptz on PostgreSQL,
 * DATETIME(6) on MySQL, never TIMESTAMP with its 2038 limit).
 */
abstract class TendrilMigration extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('tendril.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    protected function schema(): Builder
    {
        return Schema::connection($this->getConnection());
    }

    protected function driver(): string
    {
        return $this->schema()->getConnection()->getDriverName();
    }

    protected function instant(Blueprint $table, string $column): ColumnDefinition
    {
        return $this->driver() === 'pgsql'
            ? $table->timestampTz($column, 6)
            : $table->dateTime($column, 6);
    }
}
