<?php

declare(strict_types=1);

namespace Core\Events;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `events` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Standardized event model, transactional outbox, relay and internal dispatch.
 * Delivery lot: Lot 4
 */
final class EventsServiceProvider extends ServiceProvider
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
