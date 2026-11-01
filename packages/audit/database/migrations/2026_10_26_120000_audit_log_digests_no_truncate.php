<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0081 S-16 — the seal chain must be as immutable as the journal it
 * seals.
 *
 * `audit_log` refused UPDATE, DELETE **and** TRUNCATE. `audit_log_digests`
 * — the table holding the sealed days and their chained SHA-256 digests —
 * refused only UPDATE and DELETE: a `TRUNCATE` wiped every sealed day in
 * one statement, and the next seal started a **fresh chain** (`previous_digest`
 * null). The tamper evidence was then silently re-baselined, with nothing
 * left to notice it: the journal looked pristine.
 *
 * A migration of its own, not an edit of the migration that created the
 * table: that one has already run everywhere, and a migration never runs
 * twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_digests_no_truncate
            BEFORE TRUNCATE ON audit_log_digests
            FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_log_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_digests_no_truncate ON audit_log_digests');
    }
};
