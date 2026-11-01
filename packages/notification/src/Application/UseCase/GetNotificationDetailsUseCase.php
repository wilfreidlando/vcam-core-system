<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\GetNotificationDetails;
use Core\Notification\Contracts\GetNotificationDetailsCommand;
use Core\Notification\Contracts\NotificationDetailView;
use Core\Notification\Domain\Port\NotificationDeliveryRepository;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Support\Access\AccessScope;
use DateTimeInterface;

final readonly class GetNotificationDetailsUseCase implements GetNotificationDetails
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationDeliveryRepository $deliveries,
    ) {}

    public function get(GetNotificationDetailsCommand $command, AccessScope $scope): ?NotificationDetailView
    {
        $notification = $this->notifications->find($command->notificationId);
        if ($notification === null) {
            return null;
        }

        // ADR-0069 §2 / S-04 — the notification must belong to the
        // project the caller asked for AND be inside its scope. Out of
        // scope is "not found", never "forbidden": the answer must not
        // confirm that the resource exists (the filter used to sit in the
        // console controller — it belongs to the port).
        if ($notification->projectId !== $command->projectId
            || ! $scope->allowsProject($notification->projectId)
        ) {
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
