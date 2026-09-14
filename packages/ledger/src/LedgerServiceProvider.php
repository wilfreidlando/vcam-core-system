<?php

declare(strict_types=1);

namespace Core\Ledger;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `ledger` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Immutable double-entry ledger, accounts, materialized balances. The financial core: depends on no other module.
 * Delivery lot: Lot 2
 */
final class LedgerServiceProvider extends ServiceProvider
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
