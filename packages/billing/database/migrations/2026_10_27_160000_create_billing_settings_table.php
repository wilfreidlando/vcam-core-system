<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0071 §4 — the settings a project owns, read by the subscription
 * machine.
 *
 * One row per project. The timezone is the one the periods are computed
 * in (ADR-0071 §4 — the arithmetic happens in the project's timezone,
 * the storage is UTC); the two lead times bound how early a renewal is
 * prepared and how long an unpaid first invoice keeps a contract alive
 * (ADR-0072 §2).
 *
 * `project_id` is a plain uuid: a foreign key into another module's table
 * is forbidden (ADR-0033 §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table): void {
            $table->uuid('project_id')->primary();
            $table->text('timezone')->default('Africa/Douala');
            $table->char('default_currency', 3)->default('XAF');
            $table->integer('renewal_lead_days')->default(7);
            $table->integer('incomplete_expiry_hours')->default(72);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_timezone_check CHECK (timezone <> '')");
        DB::statement('ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_renewal_lead_days_check CHECK (renewal_lead_days BETWEEN 0 AND 90)');
        DB::statement('ALTER TABLE billing_settings ADD CONSTRAINT billing_settings_incomplete_expiry_hours_check CHECK (incomplete_expiry_hours BETWEEN 1 AND 720)');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
