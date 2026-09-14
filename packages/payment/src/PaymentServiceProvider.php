<?php

declare(strict_types=1);

namespace Core\Payment;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `payment` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Payment intents, orchestration state machine, provider adapters (MTN MoMo, Orange Money), refunds.
 * Delivery lot: Lot 3
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind Domain ports (interfaces) to Infrastructure adapters here.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
