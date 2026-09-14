<?php

declare(strict_types=1);

namespace Core\Notification;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `notification` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Multi-channel notification orchestration (WhatsApp/SMS/email/push/in-app), versioned templates, provider adapters.
 * Delivery lot: Lot 5
 */
final class NotificationServiceProvider extends ServiceProvider
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
