<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0060 — a notification belongs to the environment of the API key that
 * sent it; reads and webhook notifications stay within that environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->text('environment')->default('live');
        });
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_environment_check');
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('environment');
        });
    }
};
