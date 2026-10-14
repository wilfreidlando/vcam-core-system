<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0066 — single environment: sandbox notifications are deleted with
 * their deliveries, the column goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('notifications')->where('environment', 'sandbox')->pluck('id');
        DB::table('notification_deliveries')->whereIn('notification_id', $ids)->delete();
        DB::table('notifications')->whereIn('id', $ids)->delete();

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_environment_check');
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('environment');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->text('environment')->default('live');
        });
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }
};
