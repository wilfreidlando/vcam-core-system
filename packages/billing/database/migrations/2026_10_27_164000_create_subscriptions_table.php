<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0072 §1 — the contract between a customer and a project.
 *
 * The price, the currency and the interval are **frozen** on the
 * subscription: they come from the plan version the contract was signed
 * on, and a later version of the same plan does not touch them (ADR-0071
 * §Conséquences). The price is in minor units (G5).
 *
 * The foreign keys to `customers`, `plans` and `plan_versions` are
 * intra-module (ADR-0033 §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('customer_id');
            $table->uuid('plan_id');
            $table->uuid('plan_version_id');
            $table->text('status');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->text('interval_unit');
            $table->integer('interval_count');
            $table->timestampTz('anchor_at');
            $table->uuid('current_period_id')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->uuid('pending_plan_version_id')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->integer('version')->default(1);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('incomplete', 'trialing', 'active', 'past_due', 'paused', 'canceled', 'expired'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_price_check CHECK (price_minor >= 0)');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_interval_check CHECK (interval_unit IN ('day', 'week', 'month', 'year') AND interval_count BETWEEN 1 AND 365)");

        // One live contract per customer and per plan (ADR-0072 §1).
        DB::statement("CREATE UNIQUE INDEX subscriptions_live_unique ON subscriptions (customer_id, plan_id) WHERE status IN ('incomplete', 'trialing', 'active', 'past_due', 'paused')");
        DB::statement('CREATE INDEX subscriptions_project_status_idx ON subscriptions (project_id, status)');
        DB::statement('CREATE INDEX subscriptions_project_created_idx ON subscriptions (project_id, created_at, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
