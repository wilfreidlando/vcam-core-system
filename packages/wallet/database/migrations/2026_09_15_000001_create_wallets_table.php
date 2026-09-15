<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.3. No FK to `parties`/`projects` (ADR-0033 §5): the
 * owner type varies by application (a SaaS customer, an end-user of a
 * consumer platform) — the mechanism is identical either way, the owner is
 * always a Party (spec fonctionnelle §5.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('party_id');
            $table->char('currency', 3);
            $table->text('status')->default('active');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['party_id', 'currency']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
