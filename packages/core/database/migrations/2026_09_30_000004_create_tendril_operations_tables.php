<?php

use Illuminate\Database\Schema\Blueprint;
use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Operational tables: dirty buckets (aggregation work queue with late-event
 * reopening), dead letters (failure visibility) and key/value state
 * (watermarks, cursors, versions).
 */
return new class extends TendrilMigration
{
    public function up(): void
    {
        $this->schema()->create(Tables::DIRTY_BUCKETS, function (Blueprint $table): void {
            $this->instant($table, 'bucket_start')->primary();
            $table->string('reason', 32);
            $this->instant($table, 'marked_at');
        });

        $this->schema()->create(Tables::DEAD_LETTERS, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('batch_id', 26)->nullable();
            $table->text('reason');
            $table->longText('payload');
            $table->unsignedInteger('events')->default(0);
            $table->unsignedInteger('attempts')->default(1);
            $this->instant($table, 'failed_at')->index('tendril_dead_letters_failed_index');
        });

        $this->schema()->create(Tables::STATE, function (Blueprint $table): void {
            $table->string('key', 191)->primary();
            $table->text('value');
            $this->instant($table, 'updated_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::STATE);
        $this->schema()->dropIfExists(Tables::DEAD_LETTERS);
        $this->schema()->dropIfExists(Tables::DIRTY_BUCKETS);
    }
};
