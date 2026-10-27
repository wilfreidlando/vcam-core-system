<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0073 §1 — the invoice, its lines and its numbering.
 * ADR-0094 §1, §3, §5 — the tax stored per line, the optimistic lock, the
 * number's shape.
 *
 * An invoice is a **document**: once issued, its number, its lines, its
 * amounts and the snapshot of the customer are frozen (the triggers
 * below), and only its status and what has been paid of it move
 * afterwards. A document that changes after it was sent is not a
 * document.
 *
 * The number is attributed at the issuance, under a row lock on the
 * sequence of the (project, year) pair: **no gap**, ever — a sequence
 * of PostgreSQL would leave one at every rollback.
 *
 * The amounts are in minor units (G5) and the tax is a rate **per
 * line** (`tax_rate_bps`), rounded half-up on that line and **stored**
 * on the line (`tax_amount_minor`): the Core knows no tax regime, it
 * computes what it is given, and the base checks the arithmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->uuid('subscription_id')->nullable();
            $table->uuid('period_id')->nullable();
            // Attributed at the issuance: '<prefix>-<year>-<sequence>' —
            // the sequence padded to six digits (ADR-0094 §5).
            $table->text('number')->nullable();
            $table->text('status');
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('amount_paid_minor')->default(0);
            // The customer as it was at the issuance: the personal
            // values are ENCRYPTED (ADR-0083 §2, ADR-0094 §2), the
            // rest is the project's own opaque key.
            $table->jsonb('customer_snapshot')->default('{}');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            // ADR-0094 §3 — the bookkeeping reference of the invoice:
            // the id of the issuance transaction, which the void
            // counter-passes (`reversesTransactionId`, ADR-0076 §5).
            $table->uuid('ledger_transaction_id')->nullable();
            // ADR-0094 §3 — the optimistic lock, in the write.
            $table->integer('version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'open', 'paid', 'void', 'uncollectible'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_check CHECK (subtotal_minor >= 0 AND tax_minor >= 0 AND total_minor = subtotal_minor + tax_minor AND amount_paid_minor >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_number_when_issued CHECK (status = \'draft\' OR (number IS NOT NULL AND issued_at IS NOT NULL))');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_paid_amount_check CHECK (status <> \'paid\' OR amount_paid_minor = total_minor)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_void_check CHECK (status <> \'void\' OR voided_at IS NOT NULL)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_currency_check CHECK (currency = upper(currency) AND length(currency) = 3)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_version_check CHECK (version >= 1)');

        // One number per project, and one invoice per period.
        DB::statement('CREATE UNIQUE INDEX invoices_project_number_unique ON invoices (project_id, number) WHERE number IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX invoices_one_per_period ON invoices (period_id) WHERE period_id IS NOT NULL AND status <> 'void'");
        DB::statement('CREATE INDEX invoices_project_status_idx ON invoices (project_id, status)');
        DB::statement('CREATE INDEX invoices_customer_idx ON invoices (project_id, customer_id)');
        DB::statement('CREATE INDEX invoices_subscription_idx ON invoices (subscription_id) WHERE subscription_id IS NOT NULL');

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->uuid('project_id');
            // 'subscription' | 'manual' — the register opens when
            // 'usage' arrives (ADR-0090, lot 12).
            $table->text('kind');
            $table->text('description');
            $table->bigInteger('quantity')->default(1);
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('amount_minor');
            // A rate, never a tax regime: the Core computes what it is
            // given (ADR-0073 §1).
            $table->integer('tax_rate_bps')->default(0);
            // The tax of the line, rounded half-up **on that line** and
            // checked by the base (ADR-0094 §1): the invoice's tax is
            // the sum of its lines' taxes, never a rate applied to the
            // subtotal.
            $table->bigInteger('tax_amount_minor')->default(0);
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_amount_check CHECK (quantity > 0 AND unit_amount_minor >= 0 AND amount_minor = quantity * unit_amount_minor)');
        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_tax_check CHECK (tax_rate_bps BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_tax_amount_check CHECK (tax_amount_minor = (amount_minor * tax_rate_bps + 5000) / 10000)');
        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_kind_check CHECK (kind IN (\'subscription\', \'manual\'))');
        DB::statement('CREATE INDEX invoice_lines_invoice_idx ON invoice_lines (invoice_id)');

        // The numbering: one sequence per project and per year, consumed
        // under a row lock (ADR-0073 §1, ADR-0094 §5).
        Schema::create('invoice_number_sequences', function (Blueprint $table): void {
            $table->uuid('project_id');
            $table->integer('year');
            $table->bigInteger('next_value')->default(1);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['project_id', 'year']);
        });

        // ADR-0073 §1 — once issued, the document is frozen: the number,
        // the lines, the amounts and the snapshot never move again.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_issued_immutable() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;

                IF NEW.number IS DISTINCT FROM OLD.number
                   OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.subtotal_minor IS DISTINCT FROM OLD.subtotal_minor
                   OR NEW.tax_minor IS DISTINCT FROM OLD.tax_minor
                   OR NEW.total_minor IS DISTINCT FROM OLD.total_minor
                   OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                   OR NEW.customer_snapshot IS DISTINCT FROM OLD.customer_snapshot
                   OR NEW.due_at IS DISTINCT FROM OLD.due_at
                   OR NEW.issued_at IS DISTINCT FROM OLD.issued_at THEN
                    RAISE EXCEPTION 'An issued invoice is immutable (ADR-0073 §1) — void it and issue another one.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER invoice_issued_immutable BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION invoice_issued_immutable()');

        // The lines of an issued invoice are frozen too: the trigger
        // above watches the invoice, this one watches its lines — on
        // INSERT as well as on UPDATE and DELETE, or a line could still
        // be slipped into an issued invoice.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_line_immutable() RETURNS trigger AS $$
            DECLARE
                invoice_status text;
                target_invoice uuid;
            BEGIN
                target_invoice := CASE WHEN TG_OP = 'INSERT' THEN NEW.invoice_id ELSE OLD.invoice_id END;

                SELECT status INTO invoice_status FROM invoices WHERE id = target_invoice;

                IF invoice_status IS NOT NULL AND invoice_status <> 'draft' THEN
                    RAISE EXCEPTION 'The lines of an issued invoice are immutable (ADR-0073 §1).'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER invoice_line_immutable BEFORE INSERT OR UPDATE OR DELETE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION invoice_line_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS invoice_line_immutable ON invoice_lines');
        DB::statement('DROP FUNCTION IF EXISTS invoice_line_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS invoice_issued_immutable ON invoices');
        DB::statement('DROP FUNCTION IF EXISTS invoice_issued_immutable()');

        Schema::dropIfExists('invoice_number_sequences');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
