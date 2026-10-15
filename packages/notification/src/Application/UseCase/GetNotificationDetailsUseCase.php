<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\GetNotificationDetails;
use Core\Notification\Contracts\NotificationDetailView;
use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\Domain\Port\NotificationRepository;
use DateTimeInterface;

final readonly class GetNotificationDetailsUseCase implements GetNotificationDetails
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationDeliveryRepository $deliveries,
    ) {}

    public function get(string $notificationId): ?NotificationDetailView
    {
        $notification = $this->notifications->find($notificationId);
        if ($notification === null) {
            return null;
        }

        return new NotificationDetailView(
            id: $notification->id,
            projectId: $notification->projectId,
            channel: $notification->channel,
            contentType: $notification->contentType,
            toPartyRef: $notification->toPartyRef,
            clientReference: $notification->idempotencyKey,
            status: $notification->status->value,
            isFinal: $notification->status->isTerminal(),
            providerCode: $notification->providerCode,
            providerMessageId: $notification->providerMessageId,
            errorMessage: $notification->errorMessage,
            content: $notification->content,
            variables: $notification->variables,
            createdAt: $notification->createdAt?->format(DateTimeInterface::RFC3339),
            updatedAt: $notification->updatedAt?->format(DateTimeInterface::RFC3339),
            attempts: $this->deliveries->forNotification($notification->id),
        );
    }
}
