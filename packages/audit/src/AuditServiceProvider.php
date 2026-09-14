<?php

declare(strict_types=1);

namespace Core\Audit;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `audit` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Append-only audit journal of sensitive operations, correlated end to end.
 * Delivery lot: Lot 1
 */
final class AuditServiceProvider extends ServiceProvider
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
