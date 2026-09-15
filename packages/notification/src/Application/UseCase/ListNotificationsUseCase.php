<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ListNotifications;
use Core\Notification\Contracts\ListNotificationsCommand;
use Core\Notification\Contracts\NotificationPage;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;

final readonly class ListNotificationsUseCase implements ListNotifications
{
    public function __construct(
        private NotificationRepository $notifications,
    ) {}

    public function list(ListNotificationsCommand $command): NotificationPage
    {
        $status = $command->status !== null ? NotificationStatus::tryFrom($command->status) : null;

        $result = $this->notifications->findByProject($command->projectId, $status, $command->page, $command->perPage);

        $items = array_map(
            static fn (Notification $notification): NotificationView => new NotificationView(
                id: $notification->id,
                channel: $notification->channel,
                status: $notification->status,
                contentType: $notification->contentType,
                errorMessage: $notification->errorMessage,
            ),
            $result['items'],
        );

        return new NotificationPage($items, $result['total'], $command->page, $command->perPage);
    }
}
