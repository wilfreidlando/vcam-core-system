<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Notification\Contracts\Events\NotificationStatusChanged;
use Core\Notification\Contracts\NotificationStatusChangeHandler;
use Core\Notification\Domain\Exception\InvalidNotificationStatusTransition;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use Psr\Log\LoggerInterface;

/**
 * Applies a provider-reported status change to the Notification it
 * belongs to — mirrors `Core\Payment\Application\UseCase\HandlePaymentStatusChangedUseCase`.
 * Called by a future adapter's own webhook controller (ADR-0041 §6);
 * nothing in this Core calls it yet.
 *
 * `Notification` is resolved by `(providerCode, providerMessageId)` —
 * pinned at `SendNotificationUseCase` time, never searched.
 */
final readonly class HandleNotificationStatusChangedUseCase implements NotificationStatusChangeHandler
{
    /**
     * @var array<string, string>
     */
    private const EVENT_TYPES = [
        'delivered' => 'notification.delivered',
        'read' => 'notification.read',
        'failed' => 'notification.failed',
    ];

    public function __construct(
        private UnitOfWork $unitOfWork,
        private NotificationRepository $notifications,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(NotificationStatusChanged $event): void
    {
        $this->unitOfWork->transactional(function () use ($event): void {
            $notification = $this->notifications->findByProviderMessage($event->providerCode, $event->providerMessageId);

            if ($notification === null) {
                $this->logger->warning('notification.status_changed.unknown_message', [
                    'provider_code' => $event->providerCode,
                    'provider_message_id' => $event->providerMessageId,
                ]);

                return;
            }

            if ($notification->status->isTerminal()) {
                $this->logger->info('notification.status_changed.already_terminal', [
                    'notification_id' => $notification->id,
                    'status' => $notification->status->value,
                ]);

                return;
            }

            try {
                $updated = $notification->withStatusChange($event->status, $event->reason);
            } catch (InvalidNotificationStatusTransition $e) {
                // Out-of-order delivery (a `read` callback arriving before
                // `delivered`, for instance) is possible with real
                // providers — logged, not fatal, since replaying the
                // correct order later still resolves correctly.
                $this->logger->warning('notification.status_changed.invalid_transition', [
                    'notification_id' => $notification->id,
                    'from' => $notification->status->value,
                    'to' => $event->status->value,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $this->notifications->save($updated);

            $eventType = self::EVENT_TYPES[$updated->status->value] ?? null;
            if ($eventType === null) {
                return;
            }

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $updated->projectId,
                $eventType,
                [
                    'notification_id' => $updated->id,
                    'channel' => $updated->channel,
                    'status' => $updated->status->value,
                    'reason' => $event->reason,
                ],
                null,
                $this->clock->now(),
                environment: $updated->environment->value,
            ));
        });
    }
}
