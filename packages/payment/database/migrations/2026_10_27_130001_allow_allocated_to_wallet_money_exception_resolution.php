<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0077 §4 — a new resolution for a money exception: imputing the
 * money to a wallet. The column's CHECK constraint had to learn it.
 *
 * A separate migration, not an edit of the original one: the constraint
 * is already applied on every database that ran the first migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE money_exceptions DROP CONSTRAINT IF EXISTS money_exceptions_resolution_check');
        DB::statement("ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_resolution_check CHECK (resolution IS NULL OR resolution IN ('refunded_outside', 'recognized_as_revenue', 'allocated', 'allocated_to_wallet', 'no_action'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE money_exceptions DROP CONSTRAINT IF EXISTS money_exceptions_resolution_check');
        DB::statement("ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_resolution_check CHECK (resolution IS NULL OR resolution IN ('refunded_outside', 'recognized_as_revenue', 'allocated', 'no_action'))");
    }
};
