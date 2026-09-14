<?php

declare(strict_types=1);

namespace Core\Wallet;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `wallet` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Wallets: deposits, withdrawals, transfers, holds — backed by the Ledger.
 * Delivery lot: Lot 2
 */
final class WalletServiceProvider extends ServiceProvider
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
