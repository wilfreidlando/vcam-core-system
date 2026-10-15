<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0067: the reconciliation takes stuck payments in turn. `reconciled_at`
 * records the last `checkStatus()` call, whatever its outcome, so that a
 * payment the provider keeps answering `pending` for no longer sits at the
 * head of every run (starving newer payments once more than `batch_size`
 * accumulate). A payment's "last looked at" moment is
 * `GREATEST(updated_at, reconciled_at)` (PostgreSQL's GREATEST ignores
 * NULLs) — the partial expression index covers exactly the candidate filter
 * and ordering of `findStuckSince()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestampTz('reconciled_at')->nullable();
        });

        DB::statement(
            'CREATE INDEX payments_reconciliation_turn_idx '.
            'ON payments ((GREATEST(updated_at, reconciled_at))) '.
            "WHERE status IN ('pending', 'processing')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_reconciliation_turn_idx');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('reconciled_at');
        });
    }
};
