<?php

use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Fixture "next version" migration used by the upgrade harness: adds an
 * index to a populated events table without blocking writes (PostgreSQL
 * CONCURRENTLY outside a transaction, MySQL online DDL).
 */
return new class extends TendrilMigration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = $this->schema()->getConnection();
        $table = Tables::EVENTS;

        match ($this->driver()) {
            'pgsql' => $connection->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS tendril_events_source_index ON {$table} (source, occurred_at)"),
            'mysql' => $connection->statement("ALTER TABLE {$table} ADD INDEX tendril_events_source_index (source, occurred_at), ALGORITHM=INPLACE, LOCK=NONE"),
            default => $connection->statement("CREATE INDEX IF NOT EXISTS tendril_events_source_index ON {$table} (source, occurred_at)"),
        };
    }

    public function down(): void
    {
        $connection = $this->schema()->getConnection();

        match ($this->driver()) {
            'mysql' => $connection->statement('ALTER TABLE '.Tables::EVENTS.' DROP INDEX tendril_events_source_index'),
            default => $connection->statement('DROP INDEX IF EXISTS tendril_events_source_index'),
        };
    }
};
