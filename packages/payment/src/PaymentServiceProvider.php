<?php

declare(strict_types=1);

namespace Core\Payment;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the payment module into the Laravel container.
 *
 * Lot 0 + Lot 3 first slice:
 *   - registers the canonical `PaymentStatus` enum (pure, self-loading)
 *   - loads the inbound webhook route (the only HTTP surface for now)
 *
 * Adapter instantiation is intentionally NOT bound here. Adapters are
 * constructed with a `ProviderConfig` loaded from `config/providers.php`
 * by the future `ProviderConfigLoader` (Lot 3 deliverable that resolves
 * per-provider credentials, timeouts and auth strategy). Binding the
 * adapter here would couple it to a single configuration; the loader
 * is the right level for that.
 *
 * The pure-status and pure-DTO contracts (`PaymentStatus`,
 * `InitiatePaymentCommand`, `PaymentReceipt`, etc.) are autoloaded —
 * no container binding needed for value objects.
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
    }
}
