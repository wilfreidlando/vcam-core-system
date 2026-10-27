<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0072 §1 — the periods of a contract.
 *
 * The **exclusion constraint** is the heart of the model: two periods of
 * the same contract can never overlap, whatever the code does
 * (`btree_gist` gives the `uuid` equality its operator class). A payment
 * made in advance settles the `upcoming` period; it never creates a
 * second one — the model that "a new subscription per period" breaks on
 * (ADR-0072 §Contexte).
 *
 * `invoice_id` is the link to the invoice of the period (ADR-0073): it
 * stays null until the invoicing lands — a period exists before its
 * invoice does.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `btree_gist` is a trusted extension: a non-superuser role with
        // CREATE on the database may install it.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('subscription_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->uuid('project_id');
            $table->integer('seq');
            $table->text('kind');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('state');
            $table->uuid('invoice_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_kind_check CHECK (kind IN ('trial', 'paid', 'complimentary'))");
        DB::statement("ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_state_check CHECK (state IN ('upcoming', 'current', 'closed'))");
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_range_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_reason_check CHECK (kind <> \'complimentary\' OR reason IS NOT NULL)');

        DB::statement('CREATE UNIQUE INDEX subscription_periods_seq_unique ON subscription_periods (subscription_id, seq)');
        DB::statement("CREATE UNIQUE INDEX subscription_periods_one_current ON subscription_periods (subscription_id) WHERE state = 'current'");
        DB::statement('CREATE INDEX subscription_periods_project_idx ON subscription_periods (project_id, subscription_id)');

        // No two periods of the same contract may overlap (ADR-0072 §1).
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_no_overlap EXCLUDE USING gist (subscription_id WITH =, tstzrange(starts_at, ends_at) WITH &&)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_periods');
    }
};
