<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0077 §3 — one queue for all money the platform could not allocate,
 * and for every financial check that failed. Owned by `payment`; other
 * modules deposit through `Contracts\RecordMoneyException`.
 *
 * `booked_amount_minor`: what sits on the `payment_exceptions` ledger
 * account because of this exception (0 when nothing was booked — an
 * unbookable currency, a control failure). A resolution moves exactly
 * that amount out of `payment_exceptions`, never more.
 *
 * Four eyes (ADR-0081 S-15): the approver can never be the resolver, by
 * constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('kind');
            $table->text('severity');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('booked_amount_minor')->default(0);
            $table->uuid('payment_id')->nullable();
            $table->text('subject_ref')->nullable();
            $table->text('summary');
            $table->jsonb('details')->default('{}');
            $table->text('status')->default('open');
            $table->text('resolution')->nullable();
            $table->text('resolution_reference')->nullable();
            $table->text('resolution_note')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();

            $table->index(['project_id', 'status', 'created_at']);
            $table->index(['status', 'severity', 'created_at']);
            $table->index('payment_id');
        });

        DB::statement("ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_kind_check CHECK (kind IN ('amount_mismatch', 'currency_mismatch', 'overpayment', 'late_payment', 'unallocated_aging', 'reconciliation_gap', 'invariant_violation'))");
        DB::statement("ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_severity_check CHECK (severity IN ('critical', 'high', 'medium', 'low'))");
        DB::statement("ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_status_check CHECK (status IN ('open', 'awaiting_approval', 'resolved'))");
        DB::statement("ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_resolution_check CHECK (resolution IS NULL OR resolution IN ('refunded_outside', 'recognized_as_revenue', 'allocated', 'no_action'))");
        DB::statement('ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_amounts_check CHECK (amount_minor >= 0 AND booked_amount_minor >= 0 AND booked_amount_minor <= amount_minor)');
        DB::statement('ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_four_eyes CHECK (approved_by IS NULL OR approved_by <> resolved_by)');
        DB::statement("ALTER TABLE money_exceptions ADD CONSTRAINT money_exceptions_resolved_consistency CHECK ((status = 'open') = (resolution IS NULL))");
        // One exception per payment and kind: a replayed callback never opens a second one.
        DB::statement('CREATE UNIQUE INDEX money_exceptions_payment_kind_unique ON money_exceptions (payment_id, kind) WHERE payment_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('money_exceptions');
    }
};
