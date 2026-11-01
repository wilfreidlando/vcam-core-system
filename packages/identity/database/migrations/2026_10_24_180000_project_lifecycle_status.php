<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0069 §3 — the Project lifecycle, enforced by the database as well
 * as by the model: `active`, `suspended`, `archived` (final).
 *
 * The reason and the moment of the change live on the row: a suspended
 * project must be able to explain its own state to whoever asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestampTz('status_changed_at')->nullable();
            $table->text('status_reason')->nullable();
        });

        DB::statement(
            'ALTER TABLE projects ADD CONSTRAINT projects_status_check '.
            "CHECK (status IN ('active', 'suspended', 'archived'))"
        );

        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestampTz('status_changed_at')->nullable();
            $table->text('status_reason')->nullable();
        });

        DB::statement(
            'ALTER TABLE organizations ADD CONSTRAINT organizations_status_check '.
            "CHECK (status IN ('active', 'suspended', 'archived'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_status_check');
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['status_changed_at', 'status_reason']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['status_changed_at', 'status_reason']);
        });
    }
};
