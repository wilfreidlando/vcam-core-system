<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-29 #17 / G-12 — the anchor row that serialises the
 * endpoint registrations of one project.
 *
 * The ceiling on the active endpoints of a project is counted under a
 * project-scoped lock (ADR-0082 §1's discipline, applied to webhooks).
 * The lock needs a row to hold: `webhook_endpoints` has none for a
 * project that has not registered anything yet — the very moment two
 * concurrent registrations could both pass the ceiling — so the module
 * owns a one-row-per-project anchor table.
 *
 * The row carries no endpoint data: the endpoints stay in
 * `webhook_endpoints` and the count is always recomputed from them, so
 * this table can never drift from the truth.
 *
 * No FK to `projects` (ADR-0033 §5 — no cross-module database join).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_project_locks', function (Blueprint $table): void {
            $table->uuid('project_id')->primary();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_project_locks');
    }
};
