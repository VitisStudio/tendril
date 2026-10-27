<?php

use Illuminate\Database\Schema\Blueprint;
use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Analytics sessions (separate from Laravel sessions) and their acquisition
 * snapshot. Touchpoints are derived from these rows and rebuildable.
 */
return new class extends TendrilMigration
{
    public function up(): void
    {
        $this->schema()->create(Tables::SESSIONS, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('anonymous_id', 64)->nullable();
            $table->string('actor_type', 150)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('tenant_type', 150)->nullable();
            $table->string('tenant_id', 64)->nullable();
            $this->instant($table, 'started_at');
            $this->instant($table, 'last_seen_at')->nullable();
            $table->string('landing_route', 191)->nullable();
            $table->string('referrer_host', 191)->nullable();
            $table->string('referrer_class', 16)->nullable();
            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->jsonb('click_ids')->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('page_view_count')->default(0);
            $table->string('environment', 32);

            $table->index('started_at', 'tendril_sessions_started_index');
            $table->index(['anonymous_id', 'started_at'], 'tendril_sessions_anonymous_index');
            $table->index(['actor_type', 'actor_id', 'started_at'], 'tendril_sessions_actor_index');
            $table->index(['tenant_type', 'tenant_id', 'started_at'], 'tendril_sessions_tenant_index');
        });

        $this->schema()->create(Tables::TOUCHPOINTS, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('session_id', 26)->unique('tendril_touchpoints_session_unique');
            $this->instant($table, 'occurred_at');
            $table->string('anonymous_id', 64)->nullable();
            $table->string('actor_type', 150)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('tenant_type', 150)->nullable();
            $table->string('tenant_id', 64)->nullable();
            $table->string('source', 191)->nullable();
            $table->string('medium', 191)->nullable();
            $table->string('campaign', 191)->nullable();
            $table->string('content', 191)->nullable();
            $table->string('term', 191)->nullable();
            $table->string('referrer_host', 191)->nullable();
            $table->string('referrer_class', 16)->nullable();
            $table->string('landing_route', 191)->nullable();
            $table->boolean('has_click_id')->default(false);
            $table->jsonb('click_ids')->nullable();
            $table->string('environment', 32);

            $table->index('occurred_at', 'tendril_touchpoints_occurred_index');
            $table->index(['anonymous_id', 'occurred_at'], 'tendril_touchpoints_anonymous_index');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'tendril_touchpoints_actor_index');
            $table->index(['tenant_type', 'tenant_id', 'occurred_at'], 'tendril_touchpoints_tenant_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::TOUCHPOINTS);
        $this->schema()->dropIfExists(Tables::SESSIONS);
    }
};
