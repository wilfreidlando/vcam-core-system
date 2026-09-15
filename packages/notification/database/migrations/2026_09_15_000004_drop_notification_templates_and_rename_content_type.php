<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0043 — drops the template subsystem: `POST /v1/notifications`
 * (template-based) is replaced by one send endpoint per content type
 * (`/text`, `/image`, `/file`), content supplied inline in the request.
 * WAHA doesn't require a pre-approved template (unlike the official
 * WhatsApp Business Cloud API), so requiring one added friction without
 * benefit for the only provider that exists.
 *
 * `notifications.template_key` is renamed to `content_type` — it now
 * records `text`/`image`/`file` for audit, not a template reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('notification_templates');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->renameColumn('template_key', 'content_type');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->renameColumn('content_type', 'template_key');
        });

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
    }
};
