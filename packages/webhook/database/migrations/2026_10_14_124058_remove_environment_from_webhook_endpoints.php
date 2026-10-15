<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0066 — single environment: sandbox endpoints are deleted with their
 * deliveries and delivery attempts, the column goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $endpointIds = DB::table('webhook_endpoints')->where('environment', 'sandbox')->pluck('id');
        $deliveryIds = DB::table('webhook_deliveries')->whereIn('endpoint_id', $endpointIds)->pluck('id');

        DB::table('webhook_delivery_attempts')->whereIn('delivery_id', $deliveryIds)->delete();
        DB::table('webhook_deliveries')->whereIn('replayed_from_id', $deliveryIds)->update(['replayed_from_id' => null]);
        DB::table('webhook_deliveries')->whereIn('id', $deliveryIds)->delete();
        DB::table('webhook_endpoints')->whereIn('id', $endpointIds)->delete();

        DB::statement('ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS webhook_endpoints_environment_check');
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'environment', 'active']);
            $table->dropColumn('environment');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->text('environment')->default('live');
            $table->index(['project_id', 'environment', 'active']);
        });
        DB::statement("ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_environment_check CHECK (environment IN ('sandbox', 'live'))");
    }
};
