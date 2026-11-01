<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0073 §2, §4 (amended by ADR-0094 §10, §13) — the collection of
 * an invoice: the online attempt, and the offline receipt.
 *
 * `invoice_payments` is a **link table**, not a column: a payment that
 * lands late, a failed attempt, a second attempt — each one is a row.
 * A single `payment_id` column on the invoice would lose the trail of
 * the attempt that succeeded late (ADR-0073 §Contexte).
 *
 * `payment_id` is an **opaque reference**, not a foreign key: `payment`
 * is another module, and a cross-module database join is forbidden
 * (ADR-0033 §5 — the same reason the `payments` table has no FK to
 * `projects`). The same goes for `invoice_id` here, for consistency
 * with the `invoices` migration, which declares no foreign key either.
 *
 * The amounts are in minor units (G5) and the currency is a `char(3)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->uuid('project_id');
            // The opaque reference to the payment of the `payment` module
            // (no cross-module FK).
            $table->uuid('payment_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // 'pending' | 'succeeded' | 'failed' | 'canceled'
            $table->text('status')->default('pending');
            // ADR-0094 §10 — what the provider confirmed it collected, on
            // a settled attempt. `null` when the source confirmed no
            // amount. A confirmed amount that differs from the requested
            // one is NEVER imputed automatically (ADR-0077): it is the
            // evidence of the exception opened in its place.
            $table->bigInteger('confirmed_amount_minor')->nullable();
            $table->text('failure_reason')->nullable();
            // When the attempt settled — the money moved — and the
            // transaction that booked it (ADR-0073 §2, §3).
            $table->timestampTz('settled_at')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'canceled'))");
        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_amount_check CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_currency_check CHECK (currency = upper(currency) AND length(currency) = 3)');
        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_confirmed_amount_check CHECK (confirmed_amount_minor IS NULL OR confirmed_amount_minor > 0)');
        DB::statement('ALTER TABLE invoice_payments ADD CONSTRAINT invoice_payments_settled_check CHECK (status <> \'succeeded\' OR (settled_at IS NOT NULL AND ledger_transaction_id IS NOT NULL))');

        // One attempt per payment, and ONE pending attempt per invoice:
        // a second attempt requires the previous one to be failed or
        // canceled (ADR-0073 §2).
        DB::statement('CREATE UNIQUE INDEX invoice_payments_payment_unique ON invoice_payments (payment_id)');
        DB::statement("CREATE UNIQUE INDEX invoice_payments_one_pending ON invoice_payments (invoice_id) WHERE status = 'pending'");
        DB::statement('CREATE INDEX invoice_payments_invoice_idx ON invoice_payments (invoice_id)');
        DB::statement('CREATE INDEX invoice_payments_project_idx ON invoice_payments (project_id)');

        Schema::create('manual_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('invoice_id');
            // 'bank_transfer' | 'cash' | 'cheque' | 'other'
            $table->text('method');
            // The reference of the transfer, the receipt number… —
            // mandatory (ADR-0073 §4).
            $table->text('reference');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('received_at');
            // The console account that recorded it.
            $table->uuid('recorded_by');
            // The **second** console account that approved it, when the
            // amount is above the project's threshold (ADR-0073 §4).
            // ADR-0095 withdrew the second account: this column holds
            // the account that **validated** the receipt — or refused
            // it, which is the same act.
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            // 'pending' (awaiting a second account) | 'approved' |
            // 'rejected'.
            $table->text('status')->default('pending');
            $table->text('evidence_ref')->nullable();
            $table->text('note')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE manual_receipts ADD CONSTRAINT manual_receipts_method_check CHECK (method IN ('bank_transfer', 'cash', 'cheque', 'other'))");
        DB::statement("ALTER TABLE manual_receipts ADD CONSTRAINT manual_receipts_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE manual_receipts ADD CONSTRAINT manual_receipts_amount_check CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE manual_receipts ADD CONSTRAINT manual_receipts_currency_check CHECK (currency = upper(currency) AND length(currency) = 3)');
        // ADR-0095 withdrew the four eyes ADR-0073 §4 prescribed: one
        // console account is enough to validate a receipt. What the
        // base still checks is that the **validation instant** exists
        // exactly when the receipt is no longer waiting: it is the
        // instant it was validated — or refused, which is the same act
        // (ADR-0094 §13).
        DB::statement('ALTER TABLE manual_receipts ADD CONSTRAINT manual_receipts_approval_check CHECK ((status = \'pending\') = (approved_at IS NULL))');
        DB::statement('ALTER TABLE manual_receipts ADD CONSTRAINT manual_receipts_accounted_check CHECK (status <> \'approved\' OR ledger_transaction_id IS NOT NULL)');
        DB::statement('CREATE INDEX manual_receipts_invoice_idx ON manual_receipts (invoice_id)');
        DB::statement('CREATE INDEX manual_receipts_project_idx ON manual_receipts (project_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_receipts');
        Schema::dropIfExists('invoice_payments');
    }
};
