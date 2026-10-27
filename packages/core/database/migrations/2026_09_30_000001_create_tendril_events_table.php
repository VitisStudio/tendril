<?php

use Illuminate\Database\Schema\Blueprint;
use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Append-only facts. No foreign keys (partitioned MySQL tables cannot have
 * them and the store must tolerate deleted users/tenants). ULIDs are stored
 * as CHAR(26).
 *
 * With tendril.database.partitioned the table is created natively
 * partitioned by occurred_at; unique keys then include occurred_at, as both
 * PostgreSQL and MySQL require.
 */
return new class extends TendrilMigration
{
    public function up(): void
    {
        $partitioned = (bool) config('tendril.database.partitioned', false);
        $driver = $this->driver();

        if ($partitioned && $driver === 'pgsql') {
            $this->createPostgresPartitioned();
            $this->indexes();

            return;
        }

        $this->schema()->create(Tables::EVENTS, function (Blueprint $table) use ($partitioned, $driver): void {
            $table->char('id', 26);
            $this->instant($table, 'occurred_at');
            $this->instant($table, 'received_at');
            $table->string('name', 150);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('actor_type', 150)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('anonymous_id', 64)->nullable();
            $table->char('session_id', 26)->nullable();
            $table->string('tenant_type', 150)->nullable();
            $table->string('tenant_id', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->char('parent_id', 26)->nullable();
            $table->string('dedupe_key', 191);
            $table->string('source', 32);
            $table->string('environment', 32);
            $table->jsonb('properties')->nullable();
            $table->jsonb('context')->nullable();

            if ($partitioned && $driver === 'mysql') {
                $table->primary(['id', 'occurred_at']);
                $table->unique(['dedupe_key', 'occurred_at'], 'tendril_events_dedupe_unique');
            } else {
                $table->primary('id');
                $table->unique('dedupe_key', 'tendril_events_dedupe_unique');
            }
        });

        if ($partitioned && $driver === 'mysql') {
            $this->schema()->getConnection()->statement(
                'ALTER TABLE '.Tables::EVENTS.' PARTITION BY RANGE COLUMNS(occurred_at) (PARTITION p_future VALUES LESS THAN (MAXVALUE))'
            );
        }

        $this->indexes();
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::EVENTS);
    }

    private function indexes(): void
    {
        $this->schema()->table(Tables::EVENTS, function (Blueprint $table): void {
            $table->index('occurred_at', 'tendril_events_occurred_index');
            $table->index(['name', 'occurred_at'], 'tendril_events_name_occurred_index');
            $table->index(['tenant_type', 'tenant_id', 'occurred_at'], 'tendril_events_tenant_occurred_index');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'tendril_events_actor_occurred_index');
            $table->index(['anonymous_id', 'occurred_at'], 'tendril_events_anonymous_occurred_index');
            $table->index('session_id', 'tendril_events_session_index');
            $table->index('request_id', 'tendril_events_request_index');
        });
    }

    private function createPostgresPartitioned(): void
    {
        $connection = $this->schema()->getConnection();
        $table = Tables::EVENTS;

        $connection->statement(<<<SQL
            CREATE TABLE {$table} (
                id char(26) NOT NULL,
                occurred_at timestamp(6) with time zone NOT NULL,
                received_at timestamp(6) with time zone NOT NULL,
                name varchar(150) NOT NULL,
                version smallint NOT NULL DEFAULT 1,
                actor_type varchar(150) NULL,
                actor_id varchar(64) NULL,
                anonymous_id varchar(64) NULL,
                session_id char(26) NULL,
                tenant_type varchar(150) NULL,
                tenant_id varchar(64) NULL,
                request_id varchar(64) NULL,
                trace_id varchar(64) NULL,
                parent_id char(26) NULL,
                dedupe_key varchar(191) NOT NULL,
                source varchar(32) NOT NULL,
                environment varchar(32) NOT NULL,
                properties jsonb NULL,
                context jsonb NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
            SQL);

        $connection->statement("CREATE UNIQUE INDEX tendril_events_dedupe_unique ON {$table} (dedupe_key, occurred_at)");
        $connection->statement("CREATE TABLE {$table}_default PARTITION OF {$table} DEFAULT");
    }
};
