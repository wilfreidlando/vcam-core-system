<?php

declare(strict_types=1);

namespace Core\Webhook;

use Core\Events\Contracts\OutboxEventPublished;
use Core\Webhook\Application\UseCase\DeactivateWebhookEndpointUseCase;
use Core\Webhook\Application\UseCase\DeliverWebhookUseCase;
use Core\Webhook\Application\UseCase\HandleRelayedEventUseCase;
use Core\Webhook\Application\UseCase\ListDeadWebhookDeliveriesUseCase;
use Core\Webhook\Application\UseCase\ListWebhookEndpointsUseCase;
use Core\Webhook\Application\UseCase\RegisterWebhookEndpointUseCase;
use Core\Webhook\Application\UseCase\ReplayWebhookDeliveryUseCase;
use Core\Webhook\Contracts\DeactivateWebhookEndpoint;
use Core\Webhook\Contracts\DeliverWebhook;
use Core\Webhook\Contracts\HandleRelayedEvent;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Domain\Model\WebhookRetryPolicy;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use Core\Webhook\Domain\Port\WebhookSecretGenerator;
use Core\Webhook\Domain\Port\WebhookSender;
use Core\Webhook\Infrastructure\Delivery\HttpWebhookSender;
use Core\Webhook\Infrastructure\Persistence\Eloquent\EloquentWebhookDeliveryRepository;
use Core\Webhook\Infrastructure\Persistence\Eloquent\EloquentWebhookEndpointRepository;
use Core\Webhook\Infrastructure\Security\RandomWebhookSecretGenerator;
use Core\Webhook\Presentation\Console\Commands\ReplayWebhookDeliveryCommand;
use Core\Webhook\Presentation\Listeners\FanOutRelayedEventListener;
use Core\Webhook\Presentation\Support\QueuedWebhookDeliveryDispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
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
        $this->app->bind(WebhookEndpointRepository::class, EloquentWebhookEndpointRepository::class);
        $this->app->bind(WebhookDeliveryRepository::class, EloquentWebhookDeliveryRepository::class);
        $this->app->bind(WebhookSecretGenerator::class, RandomWebhookSecretGenerator::class);
        $this->app->bind(WebhookSender::class, HttpWebhookSender::class);

        $this->app->bind(RegisterWebhookEndpoint::class, RegisterWebhookEndpointUseCase::class);
        $this->app->bind(ListWebhookEndpoints::class, ListWebhookEndpointsUseCase::class);
        $this->app->bind(DeactivateWebhookEndpoint::class, DeactivateWebhookEndpointUseCase::class);
        $this->app->bind(HandleRelayedEvent::class, HandleRelayedEventUseCase::class);
        $this->app->bind(DeliverWebhook::class, DeliverWebhookUseCase::class);
        $this->app->bind(ReplayWebhookDelivery::class, ReplayWebhookDeliveryUseCase::class);
        $this->app->bind(ListDeadWebhookDeliveries::class, ListDeadWebhookDeliveriesUseCase::class);
        $this->app->bind(QueueWebhookDelivery::class, QueuedWebhookDeliveryDispatcher::class);

        $this->app->singleton(WebhookRetryPolicy::class, static function (): WebhookRetryPolicy {
            /** @var int $maxAttempts */
            $maxAttempts = config('webhook.retry.max_attempts', 8);
            /** @var int $baseDelaySeconds */
            $baseDelaySeconds = config('webhook.retry.base_delay_seconds', 30);
            /** @var int $multiplier */
            $multiplier = config('webhook.retry.multiplier', 3);
            /** @var int $maxDelaySeconds */
            $maxDelaySeconds = config('webhook.retry.max_delay_seconds', 21_600);

            return new WebhookRetryPolicy($maxAttempts, $baseDelaySeconds, $multiplier, $maxDelaySeconds);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');

        Event::listen(OutboxEventPublished::class, FanOutRelayedEventListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReplayWebhookDeliveryCommand::class,
            ]);
        }
    }
}
