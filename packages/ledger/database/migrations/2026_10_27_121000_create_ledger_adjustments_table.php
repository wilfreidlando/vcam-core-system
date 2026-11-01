<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0076 §6 — the operating corrections (`adjustment`): an amount, two
 * accounts, a motive, and the second pair of eyes that lets them reach
 * the ledger.
 *
 * The proposal lives here; the money moves only when the correction is
 * approved, and it then becomes a NORMAL ledger transaction (ADR-0076
 * §5: the ledger is append-only — a correction never alters an existing
 * entry, it produces a new one; `reverses_transaction_id` therefore stays
 * NULL).
 *
 * Four eyes (ADR-0081 S-15): the approver can never be the proposer, by
 * constraint — the same motif as `money_exceptions_four_eyes`.
 */
return new class extends Migration
{
    /**
     * The account columns of an earlier shape of this table, and the
     * names they carry now.
     *
     * @var array<string, string>
     */
    private const RENAMED_ACCOUNT_COLUMNS = [
        'debit_kind' => 'source_kind',
        'debit_reference' => 'source_reference',
        'credit_kind' => 'destination_kind',
        'credit_reference' => 'destination_reference',
    ];

    public function up(): void
    {
        // The table is introduced by this migration, so a database that
        // already has it was migrated by an earlier shape of it: the two
        // account columns were renamed once, because the G5 schema
        // guardrail reads a column named after a monetary concept
        // (`debit`, `credit`, `amount`, …) as money and demands a
        // bigint — these columns hold an account DESIGNATION. The
        // guardrail's own remedy is to rename the column so the
        // guardrail is not ambiguous.
        if (Schema::hasTable('ledger_adjustments')) {
            $this->alignAccountColumns();

            return;
        }

        Schema::create('ledger_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            // The account the money LEAVES (debit side) and the account
            // it reaches (credit side), designated the way the chart of
            // accounts designates an account: kind + reference.
            $table->text('source_kind');
            $table->text('source_reference')->default('');
            $table->text('destination_kind');
            $table->text('destination_reference')->default('');
            // Minor units, never a float (G5).
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            // The motive: a correction is never posted without one.
            $table->text('reason');
            $table->text('status')->default('proposed');
            $table->uuid('proposed_by');
            $table->uuid('approved_by')->nullable();
            // The ledger transaction the correction produced — NULL until
            // it is approved.
            $table->uuid('ledger_transaction_id')->nullable();
            $table->foreign('ledger_transaction_id')->references('id')->on('ledger_transactions');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();

            $table->index(['project_id', 'status', 'created_at']);
        });

        DB::statement("ALTER TABLE ledger_adjustments ADD CONSTRAINT ledger_adjustments_status_check CHECK (status IN ('proposed', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE ledger_adjustments ADD CONSTRAINT ledger_adjustments_amount_positive_check CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE ledger_adjustments ADD CONSTRAINT ledger_adjustments_four_eyes CHECK (approved_by IS NULL OR approved_by <> proposed_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_adjustments');
    }

    /**
     * Brings a table created by an earlier shape of this migration to the
     * current one, column by column: nothing is created, nothing is
     * dropped, the table keeps its rows.
     */
    private function alignAccountColumns(): void
    {
        foreach (self::RENAMED_ACCOUNT_COLUMNS as $from => $to) {
            if (! Schema::hasColumn('ledger_adjustments', $from)) {
                continue;
            }

            Schema::table('ledger_adjustments', function (Blueprint $table) use ($from, $to): void {
                $table->renameColumn($from, $to);
            });
        }
    }
};
