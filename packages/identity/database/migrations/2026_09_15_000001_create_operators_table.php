<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 1 identity (spec technique §5.1) — platform operators, distinct
 * from the Project/ApiKey machine-identity plane. No `organization_id`/
 * `project_id`: an operator's identity is platform-wide, not scoped to a
 * tenant (spec fonctionnelle §4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('email')->unique();
            $table->text('name');
            $table->text('password_hash');
            $table->text('role');
            // Never the plaintext secret — see EloquentOperatorRepository.
            $table->text('mfa_secret_encrypted')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE operators ADD CONSTRAINT operators_role_check '.
            "CHECK (role IN ('platform_admin', 'finance_operator', 'support_operator', 'provider_manager', 'read_only_auditor'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
