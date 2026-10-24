<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0076 §5 — two invariants the database enforces, whatever the code
 * calling it does:
 *
 * 1. Financial isolation (guard-rail G13): every entry of a transaction
 *    belongs to an account of the transaction's own project. Money cannot
 *    move between two projects, even through a bug.
 * 2. `ledger_transactions` is append-only, like `ledger_entries` already is
 *    (ADR-0005). A correction is a new transaction that names the one it
 *    reverses (`reverses_transaction_id`).
 *
 * Triggers rather than REVOKE: the application connects as the table owner,
 * and owners bypass GRANT/REVOKE (see 2026_09_15_000004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->uuid('reverses_transaction_id')->nullable();
            $table->foreign('reverses_transaction_id')->references('id')->on('ledger_transactions');
        });

        $this->installProjectIsolationTrigger();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_ledger_transaction_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'ledger_transactions is append-only: % is not permitted (ADR-0005, ADR-0076). '
                    'A correction is a new transaction referencing the one it reverses.', TG_OP
                    USING ERRCODE = 'restrict_violation';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_transactions_immutable
            BEFORE UPDATE OR DELETE ON ledger_transactions
            FOR EACH ROW
            EXECUTE FUNCTION reject_ledger_transaction_mutation();
        SQL);

        // Row triggers do not fire on TRUNCATE: a statement trigger closes
        // that last way to wipe the books (review finding).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_transactions_no_truncate
            BEFORE TRUNCATE ON ledger_transactions
            FOR EACH STATEMENT
            EXECUTE FUNCTION reject_ledger_transaction_mutation();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_truncate
            BEFORE TRUNCATE ON ledger_entries
            FOR EACH STATEMENT
            EXECUTE FUNCTION reject_ledger_transaction_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_truncate ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_no_truncate ON ledger_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_immutable ON ledger_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS reject_ledger_transaction_mutation()');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_same_project ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_ledger_entry_same_project()');

        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->dropForeign(['reverses_transaction_id']);
            $table->dropColumn('reverses_transaction_id');
        });
    }

    private function installProjectIsolationTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_ledger_entry_same_project() RETURNS TRIGGER AS $$
            DECLARE
                account_project UUID;
                transaction_project UUID;
            BEGIN
                SELECT project_id INTO account_project FROM ledger_accounts WHERE id = NEW.account_id;
                SELECT project_id INTO transaction_project FROM ledger_transactions WHERE id = NEW.transaction_id;

                IF account_project IS DISTINCT FROM transaction_project THEN
                    RAISE EXCEPTION
                        'Ledger entry crosses projects: account % belongs to project %, transaction % to project % (G13, ADR-0076).',
                        NEW.account_id, account_project, NEW.transaction_id, transaction_project
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_same_project
            BEFORE INSERT ON ledger_entries
            FOR EACH ROW
            EXECUTE FUNCTION assert_ledger_entry_same_project();
        SQL);
    }
};
