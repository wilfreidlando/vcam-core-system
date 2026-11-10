<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0118 §4 — what a project declared it wants its customers notified
 * about: a list of (event, channel) pairs, one row per project.
 *
 * The module stores the declarations without interpreting them: the events
 * are the commerce's vocabulary and the channels are the adapters' — the
 * meaning belongs to the composition (ADR-0118 §3).
 *
 * Who runs it: `NotificationServiceProvider::boot()` loads this directory
 * (`packages/notification/src/NotificationServiceProvider.php:134`), and
 * Laravel's migrator applies the file. What reads the table afterwards:
 * `EloquentSystemNotificationSettingsRepository`
 * (`packages/notification/src/Infrastructure/Persistence/Eloquent/EloquentSystemNotificationSettingsRepository.php`),
 * through `SystemNotificationSettingsRecord`.
 *
 * Schema written: `notification_system_settings` — `id` (uuid, primary
 * key), `project_id` (uuid, unique — one row per project), `preferences`
 * (jsonb — the list of `{event_type, channel}`), `created_at`/`updated_at`.
 * No table is read by the migration itself, and no FK to `projects`
 * (ADR-0033 §5).
 *
 * No other file serves this purpose: the module's other migrations hold the
 * notifications, their delivery attempts, the channel sessions and the
 * (dropped) templates — none of them stores what a project declared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_system_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->jsonb('preferences');
            $table->timestampsTz();

            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_system_settings');
    }
};
