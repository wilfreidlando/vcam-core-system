<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0059 — every attempt of every delivery, not just the last one: the
 * exact signed request sent (signature masked) and what the receiver
 * answered, how long it took, and what the Core decided next. Deleted
 * with its delivery (retention pruning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->integer('attempt');
            $table->timestampTz('attempted_at', 6);
            $table->text('url');
            $table->jsonb('request_headers');
            $table->text('request_body');
            $table->integer('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->text('outcome');
            $table->text('correlation_id')->nullable();
            $table->char('trace_id', 32)->nullable();

            $table->index(['delivery_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
    }
};
