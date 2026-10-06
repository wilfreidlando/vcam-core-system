<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0059 — the outbox row carries the trace id of the request that wrote
 * it, so the relay can restore it and every downstream job (webhook
 * delivery, payment callback) joins the same trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->char('trace_id', 32)->nullable()->after('correlation_id');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn('trace_id');
        });
    }
};
