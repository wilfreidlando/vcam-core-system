<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Project's own application/merchant-account with one payment
 * provider (security review finding — a single platform-wide MyCoolPay
 * key pair used to let one Project's callback signature verify against,
 * or even be attributed to, another Project's payment).
 *
 * `public_key` is globally unique: it's the routing key an inbound
 * webhook's `application` field is matched against, and a provider
 * assigns it uniquely per application by construction.
 * `(project_id, provider_code)` is also unique — one application per
 * Project per provider, matching "chaque application a ses clés".
 *
 * `private_key_encrypted` is `Crypt`-encrypted at rest, never hashed —
 * it must be read back in plaintext to verify each inbound callback
 * signature (same pattern as `webhook_endpoints.secret_encrypted`,
 * ADR-0040 §5).
 *
 * No FK to `projects` (ADR-0033 §5 — no cross-module database join).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->text('provider_code');
            $table->text('public_key');
            $table->text('private_key_encrypted');
            $table->timestampsTz();

            $table->unique(['project_id', 'provider_code']);
            $table->unique(['provider_code', 'public_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_applications');
    }
};
