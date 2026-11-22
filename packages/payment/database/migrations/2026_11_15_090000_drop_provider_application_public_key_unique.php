<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0126 — a payment provider's public key may serve **several
 * projects**: the same key pair is shared by several tenants of the
 * platform (one platform-wide merchant account, several billing
 * entities behind it).
 *
 * The index `UNIQUE(provider_code, public_key)` is dropped — and the
 * lookup keeps a plain index on the same pair. What the unique index
 * protected was the *resolution* of an inbound callback: the public
 * key was the routing key, and it could only point at one project.
 * That resolution now asks for **all** the applications registered
 * with that key, and binds them to the project of the payment the
 * callback names (see `MyCoolPayWebhookController`) — the transaction
 * reference, not the key, says which project a callback is about.
 *
 * `UNIQUE(project_id, provider_code)` is kept (ADR-0066): a project
 * still has one application per provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_applications', function (Blueprint $table): void {
            $table->dropUnique(['provider_code', 'public_key']);
            // The lookup is still done on this pair — every inbound
            // callback resolves its key there — so the index stays, it
            // is simply no longer unique.
            $table->index(['provider_code', 'public_key'], 'payment_provider_applications_provider_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_provider_applications', function (Blueprint $table): void {
            $table->dropIndex('payment_provider_applications_provider_key_index');
            $table->unique(['provider_code', 'public_key']);
        });
    }
};
