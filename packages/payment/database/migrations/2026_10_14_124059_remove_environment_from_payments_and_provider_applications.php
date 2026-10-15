<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0066 — single environment. Sandbox payments (simulated, never in the
 * ledger) are deleted with their journey; sandbox provider applications
 * are deleted; a project keeps one application per provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        $paymentIds = DB::table('payments')->where('environment', 'sandbox')->pluck('id');
        if (Schema::hasTable('payment_timeline_entries')) {
            DB::table('payment_timeline_entries')->whereIn('payment_id', $paymentIds)->delete();
        }
        DB::table('payments')->whereIn('id', $paymentIds)->delete();

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_environment_check');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'environment', 'created_at']);
            $table->dropColumn('environment');
        });

        DB::table('payment_provider_applications')->where('environment', 'sandbox')->delete();
        DB::statement('ALTER TABLE payment_provider_applications DROP CONSTRAINT IF EXISTS payment_provider_applications_environment_check');
        Schema::table('payment_provider_applications', function (Blueprint $table): void {
            $table->dropUnique('payment_provider_applications_project_provider_env_unique');
            $table->dropColumn('environment');
            $table->unique(['project_id', 'provider_code']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_applications', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'provider_code']);
            $table->text('environment')->default('live');
            $table->unique(['project_id', 'provider_code', 'environment'], 'payment_provider_applications_project_provider_env_unique');
        });
        DB::statement("ALTER TABLE payment_provider_applications ADD CONSTRAINT payment_provider_applications_environment_check CHECK (environment IN ('sandbox', 'live'))");

        Schema::table('payments', function (Blueprint $table): void {
            $table->text('environment')->default('live');
            $table->index(['project_id', 'environment', 'created_at']);
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }
};
