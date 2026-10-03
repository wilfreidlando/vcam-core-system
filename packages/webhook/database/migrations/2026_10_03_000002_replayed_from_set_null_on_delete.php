<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-03 §4.6 — retention pruning deletes old terminal
 * deliveries; a replay row pointing at a pruned original keeps its own
 * history and simply loses the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropForeign(['replayed_from_id']);
            $table->foreign('replayed_from_id')->references('id')->on('webhook_deliveries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropForeign(['replayed_from_id']);
            $table->foreign('replayed_from_id')->references('id')->on('webhook_deliveries');
        });
    }
};
