<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0082 §5 — rotating an endpoint's secret keeps the previous one for a
 * bounded overlap (24 h by default), encrypted at rest like the current
 * one, so every delivery can carry a second signature (`X-Signature-
 * Previous`) until the receiver has switched. Erased once the overlap ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->text('previous_secret_encrypted')->nullable();
            $table->timestampTz('previous_secret_expires_at')->nullable();
            $table->timestampTz('secret_rotated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropColumn(['previous_secret_encrypted', 'previous_secret_expires_at', 'secret_rotated_at']);
        });
    }
};
