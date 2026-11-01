<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0088 §2 — `GET /v1/notifications` reads a project's notifications
 * by `(created_at, id)`, optionally narrowed to one status or one channel.
 * Each listing is served by an index range: the table only grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['project_id', 'created_at', 'id'], 'notifications_project_cursor_idx');
            $table->index(['project_id', 'status', 'created_at', 'id'], 'notifications_project_status_cursor_idx');
            $table->index(['project_id', 'channel', 'created_at', 'id'], 'notifications_project_channel_cursor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_project_cursor_idx');
            $table->dropIndex('notifications_project_status_cursor_idx');
            $table->dropIndex('notifications_project_channel_cursor_idx');
        });
    }
};
