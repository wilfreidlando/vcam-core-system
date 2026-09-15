<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.7 — append-only audit trail of every send attempt
 * (ADR-0041 §3): a fallback cascade of three providers produces up to
 * three rows here for one `notifications` row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications');
            $table->text('provider_code');
            $table->text('provider_message_id')->nullable();
            $table->text('canonical_status');
            $table->integer('attempt')->default(1);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('notification_id');
        });

        DB::statement(
            'ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check '.
            "CHECK (canonical_status IN ('queued', 'sent', 'delivered', 'read', 'failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
