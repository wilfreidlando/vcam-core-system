<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0076 §5 — a correction is a counter-passation: it names the
 * transaction it reverses.
 *
 * The column was dead until now: nothing wrote it, so a correction
 * could never be attached to the entry it corrects (nor reconciled
 * against it, ADR-0078, nor exported with it, ADR-0088).
 *
 * It is NULLABLE in the database and REQUIRED by the domain: the
 * column is added to a table that already exists, and the rule lives in
 * the domain (`LedgerAdjustment::propose()` refuses a correction that
 * names no transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ledger_adjustments', 'reverses_transaction_id')) {
            return;
        }

        Schema::table('ledger_adjustments', function (Blueprint $table): void {
            $table->uuid('reverses_transaction_id')->nullable();
            $table->foreign('reverses_transaction_id')->references('id')->on('ledger_transactions');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_adjustments', function (Blueprint $table): void {
            $table->dropForeign(['reverses_transaction_id']);
            $table->dropColumn('reverses_transaction_id');
        });
    }
};
