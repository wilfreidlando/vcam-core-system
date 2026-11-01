<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0081 S-07 — the invitation of one email address to hold a console
 * account. The account does not exist yet: it is created at activation,
 * with the password the invitee chooses.
 *
 * Only the **hash** of the activation token is stored: the clear value
 * exists for the single response that creates the invitation and is
 * never retrievable afterwards (ADR-0069 §4, ADR-0081 S-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('email');
            // The platform role the invited account will carry; NULL
            // means an account without a platform role (ADR-0069 §1).
            $table->text('role')->nullable();
            $table->text('token_hash')->unique();
            // The inviter is the console account that issued the invitation;
            // NULL when the invitation comes from the CLI, which is not a
            // console account.
            $table->foreignUuid('invited_by')->nullable()->constrained('operators');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // The duplicate-invitation check looks an email up among the
            // invitations that are still activable.
            $table->index(['email'], 'operator_invitations_email_index');
        });

        // The role is the platform role enum, or nothing: a CHECK keeps a
        // typo out of the table (the value is read back as an enum).
        DB::statement(
            'ALTER TABLE operator_invitations ADD CONSTRAINT operator_invitations_role_check '.
            "CHECK (role IS NULL OR role IN ('platform_admin', 'finance_operator', 'support_operator', 'provider_manager', 'read_only_auditor'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_invitations');
    }
};
