<?php

declare(strict_types=1);

namespace Core\Notification;

use Core\Notification\Application\UseCase\GetNotificationStatusUseCase;
use Core\Notification\Application\UseCase\HandleNotificationStatusChangedUseCase;
use Core\Notification\Application\UseCase\SendNotificationUseCase;
use Core\Notification\Contracts\GetNotificationStatus;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Contracts\NotificationStatusChangeHandler;
use Core\Notification\Contracts\SendNotification;
use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\Domain\Port\NotificationTemplateRepository;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationDeliveryRepository;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationRepository;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationTemplateRepository;
use Core\Notification\Infrastructure\Provider\NotificationChannelProviderRegistryImpl;
use Core\Notification\Infrastructure\Routing\ConfigNotificationProviderRouter;
use Core\Support\Integration\Provider\ProviderRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: notification
 * Responsibility: multi-channel notification orchestration — provider
 * fallback routing, versioned templates, canonical `NotificationStatus`
 * (spec §9.2-§9.5, ADR-0041). No concrete channel adapter is tagged here
 * (ADR-0041 §6) — a future one only needs to tag itself `provider.gateway`
 * and this wiring resolves it automatically, exactly like `PaymentServiceProvider`
 * does for `MyCoolPayAdapter`.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Typed registry — mirrors PaymentGatewayRegistry (ADR-0039/ADR-0041 §2).
        $this->app->singleton(
            NotificationChannelProviderRegistry::class,
            function (Application $app): NotificationChannelProviderRegistry {
                return new NotificationChannelProviderRegistryImpl(
                    $app->make(ProviderRegistry::class),
                );
            },
        );

        $this->app->singleton(NotificationProviderRouter::class, function (Application $app): NotificationProviderRouter {
            /** @var array<string, list<string>> $defaults */
            $defaults = $app->make('config')->get('notification_channels.default', []);
            /** @var array<string, array<string, list<string>>> $overrides */
            $overrides = $app->make('config')->get('notification_channels.project_overrides', []);

            return new ConfigNotificationProviderRouter($defaults, $overrides);
        });

        $this->app->bind(NotificationRepository::class, EloquentNotificationRepository::class);
        $this->app->bind(NotificationTemplateRepository::class, EloquentNotificationTemplateRepository::class);
        $this->app->bind(NotificationDeliveryRepository::class, EloquentNotificationDeliveryRepository::class);

        $this->app->bind(SendNotification::class, SendNotificationUseCase::class);
        $this->app->bind(GetNotificationStatus::class, GetNotificationStatusUseCase::class);
        $this->app->bind(NotificationStatusChangeHandler::class, HandleNotificationStatusChangedUseCase::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');
    }
}
