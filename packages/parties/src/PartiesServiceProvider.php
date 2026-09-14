<?php

declare(strict_types=1);

namespace Core\Parties;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `parties` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Party references (lazy-created, no PII) and their attachments.
 * Delivery lot: Lot 2
 */
final class PartiesServiceProvider extends ServiceProvider
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
