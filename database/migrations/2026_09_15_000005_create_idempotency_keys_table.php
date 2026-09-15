<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0025: the HTTP-level idempotency store, generic across every
 * capability module. Lives at the app shell root (not inside a
 * `packages/*` module) because `Core\Support\Idempotency\IdempotencyStore`
 * — the port it backs — is a `core/support` primitive with no owning
 * capability, the same reasoning as `audit_log`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->text('key');
            $table->char('request_hash', 64);
            $table->timestampTz('locked_at');
            $table->timestampTz('completed_at')->nullable();
            $table->integer('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');

            $table->unique(['project_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
