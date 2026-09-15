<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec technique §6.7. Immutable per version — a new template edit is a
 * new row (`version` incremented), never an in-place update, so a
 * notification already sent stays reproducible from its recorded
 * `(key, channel, version)`.
 *
 * No FK to `projects` (ADR-0033 §5 — no cross-module database join).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('key');
            $table->text('channel');
            $table->text('kind')->default('session');
            $table->text('template_status')->default('approved');
            $table->integer('version')->default(1);
            $table->jsonb('body');
            $table->timestampsTz();

            $table->unique(['project_id', 'key', 'channel', 'version']);
        });

        DB::statement(
            'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_kind_check '.
            "CHECK (kind IN ('template', 'session'))"
        );
        DB::statement(
            'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_status_check '.
            "CHECK (template_status IN ('approved', 'pending'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
