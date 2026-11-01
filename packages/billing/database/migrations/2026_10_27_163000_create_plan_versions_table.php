<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0071 §1 — the terms of a plan.
 *
 * The price is in **minor units** (G5) and the currency is a `char(3)`;
 * `features` is a jsonb of *named* features, never a price (ADR-0071 §3).
 *
 * The foreign key to `plans` is **intra-module** (`billing` → `billing`),
 * which ADR-0033 §5 allows; a foreign key into another module's table
 * would not be.
 *
 * A published version is immutable — the trigger that enforces it is the
 * next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->uuid('project_id');
            $table->integer('version');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->text('interval_unit');
            $table->integer('interval_count');
            $table->integer('trial_days')->default(0);
            $table->integer('grace_days')->default(0);
            $table->boolean('grace_access')->default(true);
            $table->jsonb('features')->default('{}');
            $table->text('status')->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_price_check CHECK (price_minor >= 0)');
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_interval_check CHECK (interval_unit IN ('day', 'week', 'month', 'year') AND interval_count BETWEEN 1 AND 365)");
        DB::statement('ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_days_check CHECK (trial_days BETWEEN 0 AND 365 AND grace_days BETWEEN 0 AND 90)');
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_features_object_check CHECK (jsonb_typeof(features) = 'object')");
        DB::statement('ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_features_size_check CHECK (pg_column_size(features) <= 16384)');

        DB::statement('CREATE UNIQUE INDEX plan_versions_plan_version_unique ON plan_versions (plan_id, version)');
        DB::statement("CREATE UNIQUE INDEX plan_versions_one_draft ON plan_versions (plan_id) WHERE status = 'draft'");
        DB::statement('CREATE INDEX plan_versions_project_idx ON plan_versions (project_id, plan_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_versions');
    }
};
