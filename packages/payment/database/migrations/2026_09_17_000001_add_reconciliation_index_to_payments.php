<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ReconcileStuckPaymentsUseCase::reconcile()` (ADR-0044) runs
 * `WHERE status IN ('pending','processing') AND updated_at < ?
 * ORDER BY updated_at` every 5 minutes (scheduled) and on demand. Without
 * a matching index this is a full sequential scan + sort of `payments` —
 * a table that only grows — on every run (review finding, Lot 6
 * follow-up). A partial index on the two non-terminal statuses only
 * covers exactly this query and stays small regardless of how many
 * terminal payments accumulate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX payments_reconciliation_candidates_idx '.
            'ON payments (updated_at) '.
            "WHERE status IN ('pending', 'processing')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_reconciliation_candidates_idx');
    }
};
