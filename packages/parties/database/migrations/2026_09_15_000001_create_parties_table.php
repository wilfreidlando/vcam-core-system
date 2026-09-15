<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.3, spec fonctionnelle §4.3. Deliberately minimal: no PII
 * column is permitted here (garde-fou G4) — a Party is a reference
 * (project_id + external_ref + type), never a profile. No FK to `projects`
 * (ADR-0033 §5: no cross-module database join).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            // The product's own identifier for this actor — never a name,
            // email or phone number.
            $table->text('external_ref');
            // customer, end_user, merchant, agent, partner, platform, provider, beneficiary…
            $table->text('type');
            $table->text('kyc_status')->default('none');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['project_id', 'external_ref']);
        });

        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_kyc_status_check CHECK (kyc_status IN ('none', 'pending', 'verified', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
