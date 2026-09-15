<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.3: the materialised balance, updated in the same
 * transaction as the ledger entries that justify it, under a `SELECT ...
 * FOR UPDATE` row lock (ADR-0006) — never a field updated freely.
 * `wallets` and `wallet_balances` share this module, so the FK is fine
 * (ADR-0033 §5 forbids only *cross-module* FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_balances', function (Blueprint $table): void {
            $table->foreignUuid('wallet_id')->primary()->constrained('wallets');
            $table->bigInteger('available_minor')->default(0);
            $table->bigInteger('held_minor')->default(0);
            // Optimistic-lock fallback (ADR-0006 §8.3) — not used for
            // control flow yet, only incremented, until a measured hot spot
            // justifies switching the locking strategy.
            $table->bigInteger('version')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE wallet_balances ADD CONSTRAINT wallet_balances_available_non_negative CHECK (available_minor >= 0)');
        DB::statement('ALTER TABLE wallet_balances ADD CONSTRAINT wallet_balances_held_non_negative CHECK (held_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_balances');
    }
};
