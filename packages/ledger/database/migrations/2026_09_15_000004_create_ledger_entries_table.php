<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.4. Immutable by convention at the application layer
 * (ADR-0005: no code path ever UPDATEs or DELETEs a row) and, here,
 * immutable *structurally* too: a `BEFORE UPDATE OR DELETE` trigger rejects
 * every mutation outright, so a bug cannot silently violate the invariant
 * the way a broken convention could.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('transaction_id')->constrained('ledger_transactions');
            $table->foreignUuid('account_id')->constrained('ledger_accounts');
            $table->text('direction');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('account_id');
            // transaction_id already indexed via the FK constraint above.
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_check CHECK (direction IN ('debit', 'credit'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_positive_check CHECK (amount_minor > 0)');

        $this->installBalanceTrigger();
        $this->installImmutabilityTrigger();
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS assert_ledger_transaction_balances()');
        DB::statement('DROP FUNCTION IF EXISTS reject_ledger_entry_mutation()');
    }

    /**
     * Defence in depth for ADR-0005's core invariant: sum(credits) =
     * sum(debits) per transaction. The application already enforces this
     * before insert (Core\Ledger\Domain\Model\LedgerTransaction), but a
     * database-level check survives a bug the application layer does not.
     *
     * DEFERRABLE INITIALLY DEFERRED: entries for one transaction are
     * inserted one row at a time, so the constraint can only be evaluated
     * once the whole transaction commits — not after each individual insert.
     */
    private function installBalanceTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_ledger_transaction_balances() RETURNS TRIGGER AS $$
            DECLARE
                affected_transaction UUID;
                unbalanced_currency RECORD;
            BEGIN
                affected_transaction := COALESCE(NEW.transaction_id, OLD.transaction_id);

                -- Balance is checked PER CURRENCY, not across the whole
                -- transaction: summing XAF minor units with USD minor units
                -- as if they were the same unit would be meaningless. The
                -- application enforces single-currency transactions for now
                -- (Core\Ledger\Domain\Model\LedgerTransaction), but this
                -- trigger stays correct even the day that changes.
                SELECT currency, SUM(
                    CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END
                ) AS imbalance
                INTO unbalanced_currency
                FROM ledger_entries
                WHERE transaction_id = affected_transaction
                GROUP BY currency
                HAVING SUM(
                    CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END
                ) != 0
                LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'Ledger transaction % does not balance in %: credits minus debits = %',
                        affected_transaction, unbalanced_currency.currency, unbalanced_currency.imbalance
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER ledger_entries_balance_check
            AFTER INSERT OR UPDATE OR DELETE ON ledger_entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION assert_ledger_transaction_balances();
        SQL);
    }

    /**
     * `REVOKE UPDATE, DELETE ... FROM <role>` was considered and rejected:
     * PostgreSQL table owners bypass GRANT/REVOKE privilege checks entirely,
     * and the application connects as the same role that owns this table
     * (single-role setup — ADR-0019's Docker-first stack has no separate
     * migration-vs-application role split yet). A REVOKE here would compile
     * and silently do nothing.
     *
     * A trigger has no such loophole: it fires for every UPDATE/DELETE
     * regardless of who — owner included — issued it.
     */
    private function installImmutabilityTrigger(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_ledger_entry_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'ledger_entries is append-only: % is not permitted (ADR-0005). '
                    'A correction is a new, compensating transaction.', TG_OP
                    USING ERRCODE = 'restrict_violation';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_immutable
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW
            EXECUTE FUNCTION reject_ledger_entry_mutation();
        SQL);
    }
};
