<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0080 §2 — one row per (project, day, currency): the indicators as the
 * nightly pass saw them, so the console can draw a curve without recomputing
 * the past.
 *
 * A project that bills nothing yet still gets one row, in the neutral currency
 * `XXX` (ISO 4217 « no currency »), so a day with no money is not a day with no
 * data. The counters are repeated on each currency row of the day: a reader
 * takes them from any row. The money is bigint minor units (G5).
 *
 * The only constraint is the unique key — the primitive that makes the pass
 * replayable: running it twice for a day overwrites the row, it never doubles it.
 * No business rule lives in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_daily_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->date('day');
            $table->char('currency', 3);
            $table->bigInteger('mrr_minor')->default(0);
            $table->bigInteger('collected_minor')->default(0);
            $table->bigInteger('receivables_minor')->default(0);
            $table->integer('customers')->default(0);
            $table->integer('active_customers')->default(0);
            $table->integer('trialing')->default(0);
            $table->integer('past_due')->default(0);
            $table->integer('paused')->default(0);
            $table->integer('new_this_month')->default(0);
            $table->integer('lost_this_month')->default(0);
            $table->integer('churn_bps')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['project_id', 'day', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_daily_metrics');
    }
};
