<?php

use Illuminate\Database\Schema\Blueprint;
use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Derived layers (disposable, rebuildable from raw facts):
 * - rollups: counts and sums per hour/day, keyed by tenant/kind/dimension;
 * - actor activity: one row per (tenant, actor, date, kind, subject), the
 *   only source of distinct counts (DAU/WAU/MAU, feature uniques, retention).
 * Tenant columns use '' for "no tenant" so unique indexes hold on PostgreSQL.
 */
return new class extends TendrilMigration
{
    public function up(): void
    {
        $this->schema()->create(Tables::ROLLUPS, function (Blueprint $table): void {
            $table->id();
            $table->string('granularity', 8);
            $this->instant($table, 'bucket_start');
            $table->string('tenant_type', 150)->default('');
            $table->string('tenant_id', 64)->default('');
            $table->string('kind', 16);
            $table->string('dimension', 191);
            $table->unsignedBigInteger('count')->default(0);
            $table->bigInteger('sum')->default(0);
            $table->unsignedSmallInteger('version')->default(1);

            $table->unique(['granularity', 'bucket_start', 'tenant_type', 'tenant_id', 'kind', 'dimension'], 'tendril_rollups_unique');
            $table->index(['kind', 'dimension', 'granularity', 'bucket_start'], 'tendril_rollups_dimension_index');
            $table->index(['tenant_type', 'tenant_id', 'granularity', 'bucket_start'], 'tendril_rollups_tenant_index');
        });

        $this->schema()->create(Tables::ACTIVITY, function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('tenant_type', 150)->default('');
            $table->string('tenant_id', 64)->default('');
            $table->string('actor_type', 150);
            $table->string('actor_id', 64);
            $table->string('kind', 16);
            $table->string('subject', 191)->default('');
            $table->unsignedInteger('event_count')->default(0);

            $table->unique(['date', 'tenant_type', 'tenant_id', 'kind', 'subject', 'actor_type', 'actor_id'], 'tendril_activity_unique');
            $table->index(['tenant_type', 'tenant_id', 'kind', 'subject', 'date'], 'tendril_activity_tenant_index');
            $table->index(['kind', 'subject', 'date'], 'tendril_activity_subject_index');
            $table->index(['actor_type', 'actor_id', 'date'], 'tendril_activity_actor_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::ACTIVITY);
        $this->schema()->dropIfExists(Tables::ROLLUPS);
    }
};
