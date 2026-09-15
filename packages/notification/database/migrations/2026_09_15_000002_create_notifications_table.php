<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.7. `provider_code`/`provider_message_id` are pinned
 * only once a `send()` attempt succeeds (ADR-0041, `Notification::withProviderAttempt()`)
 * — unlike `payments`, both stay null on a notification that failed every
 * fallback without ever being accepted by a provider.
 *
 * No FK to `projects` (ADR-0033 §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('channel');
            $table->text('to_party_ref');
            $table->text('template_key');
            $table->text('idempotency_key');
            $table->text('status');
            $table->text('provider_code')->nullable();
            $table->text('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'idempotency_key']);
            $table->unique(['provider_code', 'provider_message_id']);
            $table->index('project_id');
        });

        DB::statement(
            'ALTER TABLE notifications ADD CONSTRAINT notifications_status_check '.
            "CHECK (status IN ('queued', 'sent', 'delivered', 'read', 'failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
