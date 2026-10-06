<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0059 — the "Parcours" of a payment: every step from the API request
 * to the last callback attempt, details redacted. No FK to `payments`
 * (ADR-0033 §5 convention): entries are append-only and pruned on their
 * own retention (`payment.timeline_retention_days`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_timeline_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');
            $table->uuid('project_id');
            $table->text('type');
            $table->timestampTz('occurred_at', 6);
            $table->text('summary');
            $table->jsonb('details');
            $table->text('correlation_id')->nullable();
            $table->char('trace_id', 32)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['payment_id', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_timeline_entries');
    }
};
