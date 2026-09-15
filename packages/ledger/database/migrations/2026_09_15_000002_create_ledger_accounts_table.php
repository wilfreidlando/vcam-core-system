<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.4. `kind` is an open, validated string (ADR-0033 §4) —
 * `wallet` today, `platform_fee`/`provider_fee`/`clearing` from later lots —
 * never a closed enum that would force a change to this, the most stable
 * module in the Core, every time a new account kind is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // No FK to projects (ADR-0033 §5).
            $table->uuid('project_id');
            $table->text('kind');
            // e.g. a wallet_id, or '' for a project-level account (clearing).
            // NOT NULL and never null: PostgreSQL treats NULL as distinct in
            // a unique constraint, which would let two concurrent calls each
            // create their own "the" clearing account for the same project.
            $table->text('reference')->default('');
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->timestampTz('created_at')->useCurrent();

            // One account per (project, kind, reference, currency) — the
            // resolve-or-create path relies on this to be race-safe.
            $table->unique(['project_id', 'kind', 'reference', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
