<?php

declare(strict_types=1);

namespace Core\Admin;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `admin` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Core Admin: operator console for projects, credentials, transactions, wallets, fee rules, providers, webhooks, audit and reconciliation.
 * Delivery lot: Lot 6
 */
final class AdminServiceProvider extends ServiceProvider
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
