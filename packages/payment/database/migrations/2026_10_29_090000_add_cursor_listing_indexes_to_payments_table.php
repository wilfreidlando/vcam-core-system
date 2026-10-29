<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0088 §2 — `GET /v1/payments` reads a project's payments by
 * `(created_at, id)`, optionally narrowed to one status. Both listings
 * are served by an index range: the table only grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['project_id', 'created_at', 'id'], 'payments_project_cursor_idx');
            $table->index(['project_id', 'status', 'created_at', 'id'], 'payments_project_status_cursor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_project_cursor_idx');
            $table->dropIndex('payments_project_status_cursor_idx');
        });
    }
};
