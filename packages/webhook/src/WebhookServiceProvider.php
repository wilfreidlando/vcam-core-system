<?php

declare(strict_types=1);

namespace Core\Webhook;

use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `webhook` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Consumer webhook endpoints, HMAC-signed delivery, retry/backoff, DLQ, controlled replay.
 * Delivery lot: Lot 4
 */
final class WebhookServiceProvider extends ServiceProvider
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
