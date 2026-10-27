<?php

use Illuminate\Database\Schema\Blueprint;
use VitisStudio\Tendril\Core\Database\TendrilMigration;
use VitisStudio\Tendril\Core\Support\Tables;

/**
 * Conversion definitions over facts (event + predicate + optional value).
 */
return new class extends TendrilMigration
{
    public function up(): void
    {
        $this->schema()->create(Tables::CONVERSIONS, function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->jsonb('definition');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $this->instant($table, 'created_at')->nullable();
            $this->instant($table, 'updated_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(Tables::CONVERSIONS);
    }
};
