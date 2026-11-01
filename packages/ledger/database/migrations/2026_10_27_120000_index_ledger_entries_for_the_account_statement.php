<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0076 §4 — "Pagination par curseur (created_at, id), INDEX
 * ADAPTÉS".
 *
 * The account statement filters on `account_id` and orders by
 * `(created_at, id)`. The only index `ledger_entries` carried was on
 * `account_id` alone: PostgreSQL had to read the WHOLE history of the
 * account and sort it again at every page — the cost of a statement grew
 * with the age of the account, on the very screen an operator opens to
 * understand a number.
 *
 * The composite index `(account_id, created_at, id)` serves the filter
 * AND the order in one scan, and makes the keyset cursor a range scan
 * (`created_at > X OR (created_at = X AND id > Y)`) instead of a sort.
 *
 * The single-column index on `account_id` becomes the leftmost prefix of
 * the composite one — PostgreSQL can already serve `account_id = ?`
 * with it — so keeping both would only cost writes.
 */
return new class extends Migration
{
    /**
     * The composite index that replaces the single-column one.
     */
    private const COMPOSITE_INDEX = ['account_id', 'created_at', 'id'];

    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->index(self::COMPOSITE_INDEX);
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(self::COMPOSITE_INDEX);
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->index('account_id');
        });
    }
};
