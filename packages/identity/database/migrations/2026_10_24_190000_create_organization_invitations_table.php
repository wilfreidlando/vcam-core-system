<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0069 §4 — the invitation of one email address to join one
 * organization with one role.
 *
 * Only the **hash** of the activation token is stored: the clear value
 * exists for the single response that creates the invitation and is
 * never retrievable afterwards (ADR-0069 §4, ADR-0081 S-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->text('email');
            $table->text('role');
            $table->text('token_hash')->unique();
            $table->foreignUuid('invited_by')->constrained('operators');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // The duplicate-invitation check looks an email up inside one
            // organization.
            $table->index(['organization_id', 'email'], 'organization_invitations_organization_email_index');
        });

        DB::statement(
            'ALTER TABLE organization_invitations ADD CONSTRAINT organization_invitations_role_check '.
            "CHECK (role IN ('org_owner', 'org_admin', 'org_finance', 'org_support', 'org_viewer'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
