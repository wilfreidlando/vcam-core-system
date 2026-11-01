<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0069 §2 — the audit log is scoped like everything else.
 *
 * `audit_log` carried no tenant column: every console account with the
 * `audit:read` permission could read every other tenant's journal. Two
 * nullable columns fix it:
 *
 * - `project_id` — the project the entry concerns;
 * - `organization_id` — the organization it concerns (an organization-level
 *   action, or the organization of the project).
 *
 * Both are nullable on purpose: an entry that concerns **neither** is a
 * platform event (console authentication, console account management) and
 * stays readable by a platform role only. Filling them is the caller's job —
 * the audit logger records what it is told, it does not decide what
 * deserves recording.
 *
 * The legacy rows are **not** rewritten: `audit_log` is append-only, its
 * trigger refuses any UPDATE (ADR-0081 S-16), and the journal must not be
 * altered even to add an attribution. Their historical `resource` form
 * `project:<uuid>` is matched at read time instead (`EloquentAuditQuery`),
 * so a project's past entries stay visible to the organization that owns
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->uuid('organization_id')->nullable()->after('resource');
            $table->uuid('project_id')->nullable()->after('organization_id');
        });

        DB::statement('CREATE INDEX audit_log_organization_id_index ON audit_log (organization_id)');
        DB::statement('CREATE INDEX audit_log_project_id_index ON audit_log (project_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_log_project_id_index');
        DB::statement('DROP INDEX IF EXISTS audit_log_organization_id_index');

        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropColumn(['organization_id', 'project_id']);
        });
    }
};
