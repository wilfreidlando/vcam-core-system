<?php

declare(strict_types=1);

namespace Core\Admin;

use Core\Admin\Presentation\Console\Commands\ReconcileStuckPaymentsCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `admin` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Core Admin: operator console for projects, credentials, transactions, wallets, fee rules, providers, webhooks, audit and reconciliation.
 * Delivery lot: Lot 6 (ADR-0044 — first slice: operators/RBAC foundation, audit
 * search, webhook DLQ list+replay, payment reconciliation). This module has no
 * Domain/Application of its own — it composes other modules' Contracts ports
 * (ADR-0027: "Admin compose, il n'interroge pas directement").
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No Domain ports of its own to bind — every capability this
        // module exposes is composed from another module's Contracts.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileStuckPaymentsCommand::class,
            ]);
        }
    }
}
