<?php

use Illuminate\Database\Schema\Blueprint;
use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Alias ranges (anonymous id -> actor, never actor -> actor), erasure
 * requests and the audit trail.
 */
return new class extends TendrilMigration
{
    public function up(): void
    {
        $this->schema()->create(Tables::ALIASES, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('anonymous_id', 64);
            $table->string('actor_type', 150);
            $table->string('actor_id', 64);
            $this->instant($table, 'valid_from');
            $this->instant($table, 'valid_to')->nullable();
            $table->string('reason', 32);
            $this->instant($table, 'created_at');

            $table->index(['anonymous_id', 'valid_from'], 'tendril_aliases_anonymous_index');
            $table->index(['actor_type', 'actor_id'], 'tendril_aliases_actor_index');
        });

        $this->schema()->create(Tables::ERASURES, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('subject_type', 16);
            $table->string('subject_fingerprint', 64)->index('tendril_erasures_fingerprint_index');
            $table->string('actor_pseudonym', 64);
            $table->string('anonymous_pseudonym', 64);
            $table->string('status', 16);
            $table->text('counts');
            $table->string('performed_by', 191)->nullable();
            $this->instant($table, 'requested_at');
            $this->instant($table, 'completed_at')->nullable();
        });

        $this->schema()->create(Tables::AUDIT, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('action', 64)->index('tendril_audit_action_index');
            $table->text('subject');
            $table->string('performed_by', 191)->nullable();
            $this->instant($table, 'created_at')->index('tendril_audit_created_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::AUDIT);
        $this->schema()->dropIfExists(Tables::ERASURES);
        $this->schema()->dropIfExists(Tables::ALIASES);
    }
};
