<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.8 and §12.1. Written inside the same transaction as the
 * state change it describes; a relay (Lot 4) will later read
 * `published_at IS NULL` and dispatch. Only the write half exists yet
 * (ADR-0032) — no code reads this table before Lot 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // No FK to projects (ADR-0033 §5): correlation by id, not a
            // cross-module join.
            $table->uuid('project_id');
            $table->text('type');
            $table->jsonb('payload');
            $table->text('correlation_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('project_id');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_outbox');
    }
};
