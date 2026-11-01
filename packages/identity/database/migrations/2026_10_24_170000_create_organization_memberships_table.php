<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0069 §1 — a console account may belong to several organizations
 * with a role in each. The platform role (`operators.role`) stays: it is
 * the "all organizations" scope, and an account may carry one, memberships,
 * or both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('operator_id')->constrained('operators');
            $table->text('role');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
        });

        DB::statement(
            'ALTER TABLE organization_memberships ADD CONSTRAINT organization_memberships_role_check '.
            "CHECK (role IN ('org_owner', 'org_admin', 'org_finance', 'org_support', 'org_viewer'))"
        );

        // One active membership per (organization, account): a revoked one
        // is kept for the audit trail, so the uniqueness is partial.
        DB::statement(
            'CREATE UNIQUE INDEX organization_memberships_active_unique '.
            'ON organization_memberships (organization_id, operator_id) WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
    }
};
