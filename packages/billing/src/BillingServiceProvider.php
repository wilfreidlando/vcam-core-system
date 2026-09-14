<?php

declare(strict_types=1);

namespace Core\Billing;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `billing` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Plans, subscriptions, entitlements, usage metering. Out of MVP scope.
 * Delivery lot: Post-MVP
 */
final class BillingServiceProvider extends ServiceProvider
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
