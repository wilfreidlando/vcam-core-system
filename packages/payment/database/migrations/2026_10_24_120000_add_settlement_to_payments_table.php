<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0077: what the provider confirmed it collected, and why a settled
 * payment could not be allocated. Amounts in minor units (`bigint`) with
 * their own currency (G5). `settlement_exception` is constrained by the
 * database, not only by the enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->bigInteger('confirmed_amount_minor')->nullable();
            $table->char('confirmed_currency', 3)->nullable();
            $table->text('settlement_exception')->nullable();
        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_settlement_exception_check '.
            "CHECK (settlement_exception IS NULL OR settlement_exception IN ('amount_mismatch', 'currency_mismatch'))"
        );
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_confirmed_amount_check '.
            'CHECK (confirmed_amount_minor IS NULL OR confirmed_amount_minor >= 0)'
        );
        DB::statement(
            'CREATE INDEX payments_settlement_exception_idx ON payments (project_id, created_at) '.
            'WHERE settlement_exception IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_settlement_exception_idx');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_confirmed_amount_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_settlement_exception_check');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_amount_minor', 'confirmed_currency', 'settlement_exception']);
        });
    }
};
