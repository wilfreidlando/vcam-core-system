<?php

declare(strict_types=1);

namespace Core\Storage;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `storage` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Object storage, media metadata, signed URLs, lifecycle. Out of MVP scope (advanced).
 * Delivery lot: Post-MVP
 */
final class StorageServiceProvider extends ServiceProvider
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
