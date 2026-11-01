<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0077 §3, ADR-0078 §2 — a closed invariant violation must be
 * able to open again.
 *
 * The unique index created with the table (`money_exceptions_payment_kind_unique`)
 * covered EVERY row of a `(payment_id, kind)` pair, resolved ones
 * included. Once an operator settled the exception a succeeded payment
 * had opened, no new exception could ever be recorded for that pair:
 * the hourly control kept finding the violation, kept trying to open
 * it, and the repository swallowed the unique-constraint violation —
 * so the failure was reported as "nothing to open" and the queue lost
 * the trace of it (the exception stayed `resolved`, the control passed
 * silently).
 *
 * The index is replaced by a conditional one, on the OPEN rows only:
 * one open exception per `(payment_id, kind)` — a replayed callback
 * still never opens a second one — while a RESOLVED exception no
 * longer blocks the next one.
 *
 * The `down()` puts back the FULL unique index — the very one this
 * migration replaces — so it can only apply to a base without
 * duplicates: a `resolved` row and an `open` row for the same
 * `(payment_id, kind)` are legal since this migration, and the full
 * index refuses them.
 *
 * That failure is ATOMIC and leaves the base unchanged: PostgreSQL
 * supports transactional DDL (`PostgresGrammar::$transactions = true`)
 * and Laravel's `Migration::$withinTransaction` is true by default, so
 * `Migrator::runMigration()` wraps BOTH `up()` and `down()` in a
 * transaction. A `CREATE UNIQUE INDEX` that fails therefore rolls the
 * whole `down()` back — the conditional index dropped by the first
 * statement is restored — and the migration stays recorded as run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS money_exceptions_payment_kind_unique');
        DB::statement(
            "CREATE UNIQUE INDEX money_exceptions_payment_kind_unique ON money_exceptions (payment_id, kind) WHERE payment_id IS NOT NULL AND status <> 'resolved'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS money_exceptions_payment_kind_unique');
        DB::statement(
            'CREATE UNIQUE INDEX money_exceptions_payment_kind_unique ON money_exceptions (payment_id, kind) WHERE payment_id IS NOT NULL'
        );
    }
};
