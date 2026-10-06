<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0060 — sandbox and live are isolated. A payment belongs to the
 * environment of the API key that created it; a provider application is
 * registered per (project, provider, environment). Existing rows were all
 * created through live provider credentials: they become `live`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->text('environment')->default('live');
            $table->index(['project_id', 'environment', 'created_at']);
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_environment_check CHECK (environment IN ('sandbox', 'live'))");

        Schema::table('payment_provider_applications', function (Blueprint $table): void {
            $table->text('environment')->default('live');
            $table->text('label')->nullable();
            $table->dropUnique(['project_id', 'provider_code']);
            $table->unique(['project_id', 'provider_code', 'environment'], 'payment_provider_applications_project_provider_env_unique');
        });
        DB::statement("ALTER TABLE payment_provider_applications ADD CONSTRAINT payment_provider_applications_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_provider_applications DROP CONSTRAINT payment_provider_applications_environment_check');
        Schema::table('payment_provider_applications', function (Blueprint $table): void {
            $table->dropUnique('payment_provider_applications_project_provider_env_unique');
            $table->unique(['project_id', 'provider_code']);
            $table->dropColumn(['environment', 'label']);
        });

        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_environment_check');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'environment', 'created_at']);
            $table->dropColumn('environment');
        });
    }
};
