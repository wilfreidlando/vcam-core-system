<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.4. `idempotency_key` + the unique constraint below is
 * the ledger-level idempotence mechanism (ADR-0033 §2): replaying the same
 * operation finds the existing transaction instead of posting a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            // wallet_credit, wallet_debit, wallet_hold, wallet_capture, wallet_release…
            $table->text('type');
            $table->text('external_ref')->nullable();
            // NOT NULL, not nullable: ADR-0012 requires every monetary
            // operation to carry one. A nullable column would make that a
            // convention instead of a guarantee — and would also break the
            // uniqueness check below, since PostgreSQL treats NULL as
            // distinct from NULL in a unique constraint.
            $table->text('idempotency_key');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['project_id', 'idempotency_key']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
