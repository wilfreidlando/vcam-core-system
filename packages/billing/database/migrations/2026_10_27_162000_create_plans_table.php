<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0071 §1 — the catalogue: a plan is stable, its versions carry the
 * terms.
 *
 * `code` is chosen by the project and stays stable across the versions;
 * the price, the interval, the trial and the features live in
 * `plan_versions` (the next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('code');
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('status')->default('draft');
            $table->text('visibility')->default('public');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_check CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_visibility_check CHECK (visibility IN ('public', 'private'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_code_format_check CHECK (code ~ '^[a-z0-9][a-z0-9_-]{0,63}$')");
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_name_length_check CHECK (char_length(name) BETWEEN 1 AND 200)');

        DB::statement('CREATE UNIQUE INDEX plans_project_code_unique ON plans (project_id, code)');
        DB::statement('CREATE INDEX plans_project_status_idx ON plans (project_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
