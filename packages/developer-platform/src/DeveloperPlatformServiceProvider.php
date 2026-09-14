<?php

declare(strict_types=1);

namespace Core\DeveloperPlatform;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `developer-platform` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: API gateway concerns, scopes, quotas, developer portal, OpenAPI publication, SDKs. Out of MVP scope (external).
 * Delivery lot: Post-MVP
 */
final class DeveloperPlatformServiceProvider extends ServiceProvider
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
