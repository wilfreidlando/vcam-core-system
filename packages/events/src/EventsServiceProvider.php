<?php

declare(strict_types=1);

namespace Core\Events;

use Core\Events\Contracts\EventOutbox;
use Core\Events\Infrastructure\Persistence\Eloquent\EloquentEventOutbox;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: events
 * Responsibility: transactional outbox (write side — ADR-0032). The relay
 * that reads and dispatches unpublished rows is Lot 4.
 */
final class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventOutbox::class, EloquentEventOutbox::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
