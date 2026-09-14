<?php

declare(strict_types=1);

namespace Core\Fees;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `fees` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Fee & commission rules engine: evaluation, distribution plans, simulation, applied-rule history.
 * Delivery lot: Lot 3
 */
final class FeesServiceProvider extends ServiceProvider
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
