<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0062 — the operator console shows a notification's full detail: what
 * was sent (`content`), to whom and through which session (`variables` —
 * phone number, session name), each provider attempt with its error.
 * Decision D5: personal data visible to authorised operators, retention
 * bounded by `notification.retention_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->jsonb('content')->nullable();
            $table->jsonb('variables')->nullable();
            $table->index(['project_id', 'environment', 'created_at']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn('error');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'environment', 'created_at']);
            $table->dropColumn(['content', 'variables']);
        });
    }
};
