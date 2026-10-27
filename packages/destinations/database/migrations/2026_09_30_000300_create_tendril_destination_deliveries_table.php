<?php

use Illuminate\Database\Schema\Blueprint;
use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * One row per (destination, conversion event): makes delivery idempotent and
 * retries observable. Stores ids and outcomes only, never identity payloads.
 */
return new class extends TendrilMigration
{
    public function up(): void
    {
        $this->schema()->create(Tables::DELIVERIES, function (Blueprint $table): void {
            $table->id();
            $table->string('destination', 64);
            $table->string('conversion_key', 100);
            $table->char('event_id', 26);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $this->instant($table, 'occurred_at');
            $this->instant($table, 'delivered_at')->nullable();
            $this->instant($table, 'updated_at');

            $table->unique(['destination', 'event_id'], 'tendril_deliveries_unique');
            $table->index(['destination', 'status'], 'tendril_deliveries_status_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::DELIVERIES);
    }
};
