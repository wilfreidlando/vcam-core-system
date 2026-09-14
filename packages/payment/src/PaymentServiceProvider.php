<?php

declare(strict_types=1);

namespace Core\Payment;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the payment module into the Laravel container.
 *
 * This provider is intentionally minimal at Lot 0: the payment domain
 * ships its canonical status enum and contracts, but concrete use
 * cases and provider adapters land at Lot 3 alongside the ledger.
 * The provider is loaded so the `payment` module is registered in the
 * composer-orchestrated graph and Deptrac layers can see it.
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings yet — the domain (PaymentStatus) is pure and
        // self-loading; concrete provider adapters land at Lot 3.
    }
}
