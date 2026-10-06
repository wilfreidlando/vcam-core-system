<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit 2026-10-03 §4.4 — `canceled`: the endpoint was deactivated before
 * the delivery landed. Terminal, never attempted again, never replayable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_status_check');
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check '.
            "CHECK (status IN ('pending', 'delivered', 'dead', 'canceled'))"
        );
    }

    public function down(): void
    {
        DB::statement("UPDATE webhook_deliveries SET status = 'dead' WHERE status = 'canceled'");
        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT webhook_deliveries_status_check');
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check '.
            "CHECK (status IN ('pending', 'delivered', 'dead'))"
        );
    }
};
