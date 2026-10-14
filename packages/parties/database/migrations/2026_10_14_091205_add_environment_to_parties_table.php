<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0065 — a party belongs to one environment: the same `external_ref`
 * names two distinct parties in sandbox and in live, so a sandbox key can
 * never reach a live wallet. Every existing party was created by a live
 * payment (sandbox never touched parties before): they become `live`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->text('environment')->default('live');
            $table->dropUnique(['project_id', 'external_ref']);
            $table->unique(['project_id', 'environment', 'external_ref'], 'parties_project_environment_external_ref_unique');
        });
        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parties DROP CONSTRAINT parties_environment_check');
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropUnique('parties_project_environment_external_ref_unique');
            $table->unique(['project_id', 'external_ref']);
            $table->dropColumn('environment');
        });
    }
};
