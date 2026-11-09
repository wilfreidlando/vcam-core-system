<?php

declare(strict_types=1);

namespace Core\Notification;

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Application\UseCase\CreateChannelSessionUseCase;
use Core\Notification\Application\UseCase\DeleteChannelSessionUseCase;
use Core\Notification\Application\UseCase\GetChannelSessionQrCodeUseCase;
use Core\Notification\Application\UseCase\GetChannelSessionScreenshotUseCase;
use Core\Notification\Application\UseCase\GetChannelSessionStatusUseCase;
use Core\Notification\Application\UseCase\GetChannelSessionUseCase;
use Core\Notification\Application\UseCase\GetNotificationDetailsUseCase;
use Core\Notification\Application\UseCase\GetNotificationStatusUseCase;
use Core\Notification\Application\UseCase\HandleNotificationStatusChangedUseCase;
use Core\Notification\Application\UseCase\ListChannelSessionsUseCase;
use Core\Notification\Application\UseCase\ListNotificationsByCursorUseCase;
use Core\Notification\Application\UseCase\ListNotificationsUseCase;
use Core\Notification\Application\UseCase\ManageSystemNotificationSettingsUseCase;
use Core\Notification\Application\UseCase\RequestChannelSessionPairingCodeUseCase;
use Core\Notification\Application\UseCase\SendFileNotificationUseCase;
use Core\Notification\Application\UseCase\SendImageNotificationUseCase;
use Core\Notification\Application\UseCase\SendSystemNotificationUseCase;
use Core\Notification\Application\UseCase\SendTextNotificationUseCase;
use Core\Notification\Contracts\CreateChannelSession;
use Core\Notification\Contracts\DeleteChannelSession;
use Core\Notification\Contracts\GetChannelSession;
use Core\Notification\Contracts\GetChannelSessionQrCode;
use Core\Notification\Contracts\GetChannelSessionScreenshot;
use Core\Notification\Contracts\GetChannelSessionStatus;
use Core\Notification\Contracts\GetNotificationDetails;
use Core\Notification\Contracts\GetNotificationStatus;
use Core\Notification\Contracts\ListChannelSessions;
use Core\Notification\Contracts\ListNotifications;
use Core\Notification\Contracts\ListNotificationsByCursor;
use Core\Notification\Contracts\ManageSystemNotificationSettings;
use Core\Notification\Contracts\NotificationChannelProviderRegistry;
use Core\Notification\Contracts\NotificationExportRecords;
use Core\Notification\Contracts\NotificationProviderRouter;
use Core\Notification\Contracts\NotificationStats;
use Core\Notification\Contracts\NotificationStatusChangeHandler;
use Core\Notification\Contracts\NotificationStatusMapper;
use Core\Notification\Contracts\RequestChannelSessionPairingCode;
use Core\Notification\Contracts\SendFileNotification;
use Core\Notification\Contracts\SendImageNotification;
use Core\Notification\Contracts\SendSystemNotification;
use Core\Notification\Contracts\SendTextNotification;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\Domain\Port\QuotaGuard;
use Core\Notification\Domain\Port\SystemNotificationSettingsRepository;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentChannelSessionRepository;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationDeliveryRepository;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationRepository;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentNotificationStats;
use Core\Notification\Infrastructure\Persistence\Eloquent\EloquentSystemNotificationSettingsRepository;
use Core\Notification\Infrastructure\Provider\NotificationChannelProviderRegistryImpl;
use Core\Notification\Infrastructure\Provider\Waha\WahaAdapter;
use Core\Notification\Infrastructure\Provider\Waha\WahaStatusMapper;
use Core\Notification\Infrastructure\Quota\BillingQuotaGuard;
use Core\Notification\Infrastructure\Routing\ConfigNotificationProviderRouter;
use Core\Notification\Presentation\Console\Commands\RedactExpiredNotificationContentCommand;
use Core\Notification\Presentation\Export\NotificationExportRecordsReader;
use Core\Support\Integration\Configuration\ProviderConfigRegistry;
use Core\Support\Integration\ExternalApiCaller;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Provider\ProviderRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Bounded context: notification
 * Responsibility: multi-channel notification orchestration — provider
 * fallback routing, canonical `NotificationStatus` (spec §9.2-§9.5,
 * ADR-0041, revised by ADR-0043 to drop the template subsystem in favour
 * of one send endpoint per content type), plus the WhatsApp/WAHA
 * adapter and its channel-session (QR/pairing-code/screenshot) proxy
 * endpoints (ADR-0042). Any future channel adapter only needs to tag
 * itself `provider.gateway` — this wiring resolves it automatically,
 * exactly like `PaymentServiceProvider` does for `MyCoolPayAdapter`.
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
        $this->app->bind(NotificationDeliveryRepository::class, EloquentNotificationDeliveryRepository::class);
        $this->app->bind(ChannelSessionRepository::class, EloquentChannelSessionRepository::class);

        $this->app->bind(NotificationSendCascade::class, NotificationSendCascade::class);
        $this->app->bind(QuotaGuard::class, BillingQuotaGuard::class);

        // ADR-0118 §2 — the Core's own voice: the message leaves from the
        // platform's global session, whose name comes from the
        // configuration (`notification.system_session`, default value
        // `default`). Resolved by exact ownership, never by
        // `findUsableFor`: a Project's session of the same name does not
        // answer for the platform.
        $this->app->bind(SendSystemNotification::class, function (Application $app): SendSystemNotification {
            $configured = $app->make('config')->get('notification.system_session');
            $sessionRef = is_string($configured) && $configured !== '' ? $configured : 'default';

            return new SendSystemNotificationUseCase(
                cascade: $app->make(NotificationSendCascade::class),
                sessions: $app->make(ChannelSessionRepository::class),
                logger: $app->make(LoggerInterface::class),
                platformSessionRef: $sessionRef,
            );
        });

        // ADR-0118 §4 — what a Project declared it wants its customers
        // notified about.
        $this->app->bind(SystemNotificationSettingsRepository::class, EloquentSystemNotificationSettingsRepository::class);
        $this->app->bind(ManageSystemNotificationSettings::class, ManageSystemNotificationSettingsUseCase::class);
        $this->app->bind(SendTextNotification::class, SendTextNotificationUseCase::class);
        $this->app->bind(SendImageNotification::class, SendImageNotificationUseCase::class);
        $this->app->bind(SendFileNotification::class, SendFileNotificationUseCase::class);
        $this->app->bind(GetNotificationStatus::class, GetNotificationStatusUseCase::class);
        $this->app->bind(ListNotifications::class, ListNotificationsUseCase::class);
        $this->app->bind(ListNotificationsByCursor::class, ListNotificationsByCursorUseCase::class);
        // ADR-0088 §3 — the rows of the project export, in the public API's shape.
        $this->app->bind(NotificationExportRecords::class, NotificationExportRecordsReader::class);
        $this->app->bind(GetNotificationDetails::class, GetNotificationDetailsUseCase::class);
        $this->app->bind(NotificationStats::class, EloquentNotificationStats::class);
        $this->app->bind(NotificationStatusChangeHandler::class, HandleNotificationStatusChangedUseCase::class);

        $this->app->bind(CreateChannelSession::class, CreateChannelSessionUseCase::class);
        $this->app->bind(DeleteChannelSession::class, DeleteChannelSessionUseCase::class);
        $this->app->bind(ListChannelSessions::class, ListChannelSessionsUseCase::class);
        $this->app->bind(GetChannelSession::class, GetChannelSessionUseCase::class);
        $this->app->bind(GetChannelSessionQrCode::class, GetChannelSessionQrCodeUseCase::class);
        $this->app->bind(GetChannelSessionScreenshot::class, GetChannelSessionScreenshotUseCase::class);
        $this->app->bind(GetChannelSessionStatus::class, GetChannelSessionStatusUseCase::class);
        $this->app->bind(RequestChannelSessionPairingCode::class, RequestChannelSessionPairingCodeUseCase::class);

        $this->registerWahaAdapter();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RedactExpiredNotificationContentCommand::class]);
        }
    }

    /**
     * Canonical wiring pattern for a channel adapter (ADR-0042, mirrors
     * `PaymentServiceProvider::register()` for `MyCoolPayAdapter`): bind
     * the status mapper singleton, bind the adapter singleton, tag it
     * `provider.gateway`. `WahaAdapter` is bound to `NotificationStatusMapper`
     * too so `WahaWebhookController` can map raw `ack` values without
     * depending on the adapter class directly.
     */
    private function registerWahaAdapter(): void
    {
        $code = ProviderCode::fromString('whatsapp-waha');

        $this->app->singleton(WahaStatusMapper::class, static fn (): WahaStatusMapper => new WahaStatusMapper($code));
        $this->app->bind(NotificationStatusMapper::class, WahaStatusMapper::class);

        $this->app->singleton(WahaAdapter::class, function (Application $app) use ($code): WahaAdapter {
            $configs = $app->make(ProviderConfigRegistry::class);

            return new WahaAdapter(
                caller: $app->make(ExternalApiCaller::class),
                config: $configs->configFor($code),
                statusMapper: $app->make(WahaStatusMapper::class),
            );
        });

        $this->app->tag([WahaAdapter::class], 'provider.gateway');
    }
}
