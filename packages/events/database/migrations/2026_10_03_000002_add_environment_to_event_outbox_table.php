<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0060 — an event carries the environment it happened in, so a sandbox
 * payment's webhook only reaches the project's sandbox endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->text('environment')->default('live');
        });
    }

    public function down(): void
    {
        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->dropColumn('environment');
        });
    }
};
