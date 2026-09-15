<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0044: the new `AuditQuery` read port filters on `actor`/`action` in
 * addition to the columns already indexed (`resource`, `correlation_id`,
 * `created_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->index('actor');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropIndex(['actor']);
            $table->dropIndex(['action']);
        });
    }
};
