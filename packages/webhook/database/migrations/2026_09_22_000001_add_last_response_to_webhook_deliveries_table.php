<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review finding, user-directed: an operator diagnosing a failed delivery
 * could see `last_error` (a formatted message) but never what the
 * receiving endpoint actually sent back. Both columns are null for an
 * attempt that never received a response at all (DNS/connect/timeout
 * failure) — see `HttpWebhookSender`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->integer('last_response_status')->nullable()->after('last_error');
            $table->text('last_response_body')->nullable()->after('last_response_status');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['last_response_status', 'last_response_body']);
        });
    }
};
