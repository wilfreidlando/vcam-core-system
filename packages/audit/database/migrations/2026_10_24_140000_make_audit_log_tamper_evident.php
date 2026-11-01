<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0081 S-16 — the audit log cannot be altered by the application:
 *
 * - UPDATE, DELETE and TRUNCATE on `audit_log` are refused by triggers
 *   (the application owns the table, so REVOKE would do nothing — same
 *   reasoning as the ledger, 2026_09_15_000004);
 * - each past day is sealed with a SHA-256 digest chained to the previous
 *   day's (`audit_log_digests`), so altering a row — even after disabling a
 *   trigger with owner rights — breaks the chain and `audit:verify` says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_audit_log_mutation() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_log is append-only: % is not permitted (ADR-0081 S-16).', TG_OP
                    USING ERRCODE = 'restrict_violation';
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        $this->installImmutabilityTriggers();

        Schema::create('audit_log_digests', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->integer('entries_count');
            $table->bigInteger('first_entry_id')->nullable();
            $table->bigInteger('last_entry_id')->nullable();
            $table->char('previous_digest', 64)->nullable();
            $table->char('digest', 64);
            $table->timestampTz('sealed_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_digests_immutable
            BEFORE UPDATE OR DELETE ON audit_log_digests
            FOR EACH ROW EXECUTE FUNCTION reject_audit_log_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_digests');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_truncate ON audit_log');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_immutable ON audit_log');
        DB::unprepared('DROP FUNCTION IF EXISTS reject_audit_log_mutation() CASCADE');
    }

    private function installImmutabilityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_immutable
            BEFORE UPDATE OR DELETE ON audit_log
            FOR EACH ROW EXECUTE FUNCTION reject_audit_log_mutation();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_no_truncate
            BEFORE TRUNCATE ON audit_log
            FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_log_mutation();
        SQL);
    }
};
