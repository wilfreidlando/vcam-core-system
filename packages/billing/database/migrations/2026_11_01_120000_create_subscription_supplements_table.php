<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0117 §2 — the supplements of a contract: units bought on top of
 * the limit of a metered feature.
 *
 * A supplement is **not** a subscription and **not** a second counter
 * (ADR-0074 §3): it is a line of purchase that adds a quantity to the
 * limit the plan version already carries, on the very meter of that
 * feature.
 *
 * The terms are frozen at the purchase — the meter, the quantity, the
 * unit price and the currency (G5: minor units, never a float) — like
 * the terms of a contract (ADR-0072 §1). The `status` is the life of
 * the purchase: `pending` while its invoice is not settled, `active`
 * once it is, `canceled` when that invoice was annulled. Only `active`
 * grants the allowance (ADR-0117 §2).
 *
 * No business rule lives in the schema: the formats, the sign of the
 * quantity, the non-negative price and the life of the row are guarded
 * by the code (`SubscriptionSupplement`, ADR-0074 §1). The indexes are
 * the ones the lookups need — by contract, by invoice, and by project
 * for the listing (ADR-0088).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_supplements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('subscription_id');
            $table->uuid('customer_id');
            // The metered feature this supplement extends (ADR-0074 §3).
            $table->string('meter', 64);
            $table->string('feature_key', 64);
            $table->bigInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->char('currency', 3);
            $table->string('status', 16);
            // The invoice that carries the purchase (ADR-0117 §4).
            $table->uuid('invoice_id')->nullable();
            // The period of the contract the supplement was bought for.
            $table->uuid('period_id')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['subscription_id', 'status'], 'subscription_supplements_contract_idx');
            $table->index('invoice_id', 'subscription_supplements_invoice_idx');
            $table->index(['project_id', 'created_at', 'id'], 'subscription_supplements_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_supplements');
    }
};
