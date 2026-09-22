<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A publisher-set hint: this event already has its own dedicated,
 * point-to-point delivery arranged (e.g. a payment's `callback_url`), so
 * the generic Project-wide webhook-endpoint fan-out (`webhook` module)
 * should skip it for this one event — never a blanket "skip every
 * downstream consumer" signal (a future `notification`/`billing`
 * listener on the same event type is unaffected; see `DomainEvent`'s
 * docblock). User-directed design: avoid a payment notifying both its
 * own `callback_url` and every registered webhook endpoint for the same
 * status change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->boolean('skip_webhook_endpoint_fan_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->dropColumn('skip_webhook_endpoint_fan_out');
        });
    }
};
