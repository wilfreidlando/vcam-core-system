<?php

declare(strict_types=1);

namespace Core\Events;

use Core\Events\Contracts\EventOutbox;
use Core\Events\Contracts\RelayOutboxEvents;
use Core\Events\Infrastructure\Persistence\Eloquent\EloquentEventOutbox;
use Core\Events\Infrastructure\Relay\OutboxRelay;
use Core\Events\Presentation\Console\Commands\RelayOutboxEventsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: events
 * Responsibility: transactional outbox (write side — ADR-0032) and its
 * relay (read side — ADR-0040, Lot 4): the single reader of `event_outbox`,
 * republishing each row as `Contracts\OutboxEventPublished` for downstream
 * consumers (`webhook` today) to subscribe to.
 */
final class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventOutbox::class, EloquentEventOutbox::class);
        $this->app->bind(RelayOutboxEvents::class, OutboxRelay::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RelayOutboxEventsCommand::class,
            ]);
        }
    }
}
