<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\GetNotificationStatus;
use Core\Notification\Contracts\GetNotificationStatusCommand;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Domain\Exception\NotificationNotFound;
use Core\Notification\Domain\Port\NotificationRepository;

/**
 * Mirrors `Core\Payment\Application\UseCase\GetPaymentStatusUseCase`:
 * the same anti-enumeration convention (not-found and wrong-project
 * return the identical exception).
 */
final readonly class GetNotificationStatusUseCase implements GetNotificationStatus
{
    public function __construct(
        private NotificationRepository $notifications,
    ) {}

    public function get(GetNotificationStatusCommand $command): NotificationView
    {
        $notification = $this->notifications->find($command->notificationId);

        if ($notification === null
            || $notification->projectId !== $command->requestingProjectId) {
            throw NotificationNotFound::withId($command->notificationId);
        }

        return new NotificationView(
            id: $notification->id,
            channel: $notification->channel,
            status: $notification->status->value,
            contentType: $notification->contentType,
            errorMessage: $notification->errorMessage,
        );
    }
}
