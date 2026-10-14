<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0065.
 *
 * `wallets.environment` mirrors its party's: checked on every read and
 * mutation, so a key never reaches a wallet of the other environment.
 *
 * `wallet_operations` keeps what the ledger does not carry for a transfer
 * or a withdrawal requested over HTTP — the caller's idempotency key,
 * description, opaque metadata and the legs as requested. The ledger stays
 * the source of truth for amounts and balances; the two are joined by
 * `ledger_transactions.external_ref = wallet_operations.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->text('environment')->default('live');
            $table->index(['project_id', 'environment']);
        });
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_environment_check CHECK (environment IN ('sandbox', 'live'))");

        Schema::create('wallet_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('environment');
            $table->text('type');
            $table->text('idempotency_key');
            $table->char('currency', 3);
            $table->bigInteger('total_minor');
            $table->text('description')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->jsonb('legs');
            $table->text('external_reference')->nullable();
            $table->uuid('ledger_transaction_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['project_id', 'environment', 'idempotency_key'], 'wallet_operations_idempotency_unique');
        });
        DB::statement("ALTER TABLE wallet_operations ADD CONSTRAINT wallet_operations_environment_check CHECK (environment IN ('sandbox', 'live'))");
        DB::statement("ALTER TABLE wallet_operations ADD CONSTRAINT wallet_operations_type_check CHECK (type IN ('transfer', 'withdrawal'))");
        DB::statement('ALTER TABLE wallet_operations ADD CONSTRAINT wallet_operations_total_positive CHECK (total_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_operations');

        DB::statement('ALTER TABLE wallets DROP CONSTRAINT wallets_environment_check');
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'environment']);
            $table->dropColumn('environment');
        });
    }
};
