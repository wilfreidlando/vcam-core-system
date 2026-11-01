<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0074 §1 — the usage journal, the idempotency ledger and the counters.
 *
 * Three tables, three jobs:
 *
 * - `usage_records` is the **truth and the audit**: the store only ever
 *   inserts into it. Monthly partitioning is an operations concern
 *   (ADR-0090) and is not declared here.
 * - `usage_idempotency` carries the uniqueness of `(project, meter, key)`:
 *   the one primitive that makes a replayed call count once even when two
 *   processes race.
 * - `usage_counters` is a **projection** kept in the same transaction, so a
 *   decision costs one conditional UPDATE.
 *
 * No business rule lives in the schema: the formats, the sign of a
 * quantity, the non-negative counter and the append-only journal are
 * guarded by the code (`ConsumeUsageUseCase`, `PostgresUsageStore`). The
 * only constraints are the unique keys, because uniqueness is not a rule
 * but the concurrency primitive of the idempotency.
 *
 * A counter is identified by `(project, owner, meter, window_start)`:
 * `owner` is the customer id, or `-` for usage attributed to the project
 * itself; a gauge (reset = never) has no window and carries the epoch as
 * its `window_start`. No column is nullable on the key, so plain unique
 * indexes are enough.
 *
 * The three settings that drive it live on `billing_settings`
 * (ADR-0074 §5, §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('customer_id')->nullable();
            $table->string('meter', 64);
            $table->bigInteger('quantity');
            $table->string('idempotency_key', 255);
            $table->string('source', 64);
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->useCurrent();

            // The three listings of the console, each ordered by (recorded_at, id).
            $table->index(['project_id', 'recorded_at', 'id']);
            $table->index(['project_id', 'meter', 'recorded_at', 'id']);
            $table->index(['project_id', 'customer_id', 'recorded_at', 'id']);
        });

        Schema::create('usage_idempotency', function (Blueprint $table): void {
            $table->uuid('project_id');
            $table->string('meter', 64);
            $table->string('idempotency_key', 255);
            $table->uuid('usage_record_id');
            $table->string('outcome', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['project_id', 'meter', 'idempotency_key']);
        });

        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('owner', 36);
            $table->uuid('customer_id')->nullable();
            $table->string('meter', 64);
            $table->timestampTz('window_start');
            $table->timestampTz('window_end')->nullable();
            $table->bigInteger('limit_value')->nullable();
            $table->string('overage', 8)->default('block');
            $table->integer('threshold_bps')->nullable();
            $table->bigInteger('used')->default(0);
            $table->timestampTz('threshold_notified_at')->nullable();
            $table->timestampTz('limit_notified_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['project_id', 'owner', 'meter', 'window_start'], 'usage_counters_window_unique');
        });

        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->json('meter_mappings')->nullable();
            $table->boolean('refund_failed_usage')->default(true);
            $table->boolean('require_customer_ref')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('billing_settings', function (Blueprint $table): void {
            $table->dropColumn(['meter_mappings', 'refund_failed_usage', 'require_customer_ref']);
        });

        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('usage_idempotency');
        Schema::dropIfExists('usage_records');
    }
};
