<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0060 — an endpoint belongs to the environment of the key that
 * registered it and only receives that environment's events. Existing
 * endpoints become `live`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->text('environment')->default('live');
            $table->index(['project_id', 'environment', 'active']);
        });
        DB::statement("ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE webhook_endpoints DROP CONSTRAINT webhook_endpoints_environment_check');
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'environment', 'active']);
            $table->dropColumn('environment');
        });
    }
};
