<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0038 §3: the anchor that lets a status check or inbound webhook
 * resolve the correct provider directly — `provider_code` and
 * `provider_transaction_ref` are pinned at creation, never re-resolved.
 *
 * No FK to `projects` (ADR-0033 §5 — no cross-module database join).
 * No FK to `currencies` either, for the same reason: `currencies` is
 * owned by `ledger`, and `payment` validates the code at the
 * Application layer instead of coupling schemas across modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('channel');
            $table->text('provider_code');
            // Our own idempotency key (mirrors ledger_transactions'
            // pattern): NOT NULL — PostgreSQL treats NULL as distinct in
            // a unique constraint, which would defeat the uniqueness
            // check below.
            $table->text('idempotency_key');
            $table->text('provider_transaction_ref');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('status');
            $table->boolean('requires_action')->default(false);
            $table->text('action_hint')->nullable();
            $table->text('payment_url')->nullable();
            $table->text('error_message')->nullable();
            $table->text('return_url')->nullable();
            $table->text('wallet_recipient_external_ref')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'idempotency_key']);
            $table->unique(['provider_code', 'provider_transaction_ref']);
        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_status_check '.
            "CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled'))"
        );
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
