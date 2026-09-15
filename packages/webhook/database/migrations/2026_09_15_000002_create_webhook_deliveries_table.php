<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0040 §4: the partial unique index — not a plain `unique()` — is what
 * keeps the at-least-once relay idempotent while still allowing
 * `webhook:replay` to create an intentional second row for the same
 * `(endpoint_id, event_id)` pair. `replayed_from_id IS NULL` selects only
 * the rows the fan-out itself creates; a replayed row (`replayed_from_id`
 * set) is exempt on purpose.
 *
 * `event_id` has no FK to `event_outbox` (owned by a different module,
 * ADR-0033 §5) — it is a correlation id, not a join key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('endpoint_id')->constrained('webhook_endpoints');
            $table->uuid('project_id');
            $table->uuid('event_id');
            $table->text('event_type');
            $table->jsonb('payload');
            $table->text('status');
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            // Not ->constrained() here: a self-referencing FK added inside
            // the same CREATE TABLE statement fails in PostgreSQL ("no
            // unique constraint matching given keys") because the table's
            // own primary key is not yet visible to the FK check within
            // one DDL statement. Added as a separate ALTER below, once the
            // table (and its PK) exists.
            $table->uuid('replayed_from_id')->nullable();
            $table->timestampsTz();

            $table->index('project_id');
            $table->index(['status', 'next_retry_at']);
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->foreign('replayed_from_id')->references('id')->on('webhook_deliveries');
        });

        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check '.
            "CHECK (status IN ('pending', 'delivered', 'dead'))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX webhook_deliveries_endpoint_event_unique '.
            'ON webhook_deliveries (endpoint_id, event_id) WHERE replayed_from_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
