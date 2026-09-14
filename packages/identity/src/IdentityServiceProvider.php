<?php

declare(strict_types=1);

namespace Core\Identity;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `identity` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Organizations, Projects, API keys, OAuth clients, operators, RBAC.
 * Delivery lot: Lot 1
 */
final class IdentityServiceProvider extends ServiceProvider
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
