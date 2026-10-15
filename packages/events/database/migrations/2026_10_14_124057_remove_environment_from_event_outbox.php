<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0066 — single environment: sandbox events are dropped, the column
 * goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_outbox')->where('environment', 'sandbox')->delete();

        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->dropColumn('environment');
        });
    }

    public function down(): void
    {
        Schema::table('event_outbox', function (Blueprint $table): void {
            $table->text('environment')->default('live');
        });
    }
};
