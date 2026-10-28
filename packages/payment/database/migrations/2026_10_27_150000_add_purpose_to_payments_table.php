<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0076 §3 (amended by ADR-0094 §9) — the payment carries the
 * INTENTION of the collection, which decides the ledger entry written at
 * the confirmation:
 *
 *  - `direct` — the default: a plain collection for the project.
 *    `D clearing:<prestataire> / C revenue`;
 *  - `wallet_credit` — the caller opted into a wallet credit
 *    (`wallet_recipient_external_ref`). `D clearing:<prestataire> / C
 *    wallet:<partie>` (ADR-0037);
 *  - `invoice` — set by `billing` (ADR-0073): `D clearing:<prestataire> /
 *    C collections`, then `billing` allocates the collection onto the
 *    customer's receivable.
 *
 * The vocabulary is constrained **by the database**, not only by the
 * application: a payment that claims an intention nobody honours would
 * be booked against the wrong account, and the money would be counted
 * twice (ADR-0094 §9 — the trap this column exists to close).
 *
 * `NOT NULL DEFAULT 'direct'`: the default IS the historical behaviour
 * (ADR-0086 — no published contract is broken).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->text('purpose')->default('direct');
        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_purpose_check '.
            "CHECK (purpose IN ('direct', 'wallet_credit', 'invoice'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_purpose_check');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
