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
use Core\Support\Access\AccessScope;
use DateTimeInterface;

final readonly class ListNotificationsUseCase implements ListNotifications
{
    public function __construct(
        private NotificationRepository $notifications,
    ) {}

    public function list(ListNotificationsCommand $command, AccessScope $scope): NotificationPage
    {
        $status = $command->status !== null ? NotificationStatus::tryFrom($command->status) : null;

        // ADR-0069 §2 — a project outside the account's scope yields
        // nothing rather than another tenant's notifications.
        if (! $scope->allowsProject($command->projectId)) {
            return new NotificationPage([], 0, $command->page, $command->perPage);
        }

        $result = $this->notifications->findByProject(
            $command->projectId,
            $status,
            $command->page,
            $command->perPage,
            $scope->projectFilter(),
        );

        $items = array_map(
            static fn (Notification $notification): NotificationView => new NotificationView(
                id: $notification->id,
                channel: $notification->channel,
                status: $notification->status->value,
                contentType: $notification->contentType,
                errorMessage: $notification->errorMessage,
                toPartyRef: $notification->toPartyRef,
                providerCode: $notification->providerCode,
                createdAt: $notification->createdAt?->format(DateTimeInterface::RFC3339),
            ),
            $result['items'],
        );

        return new NotificationPage($items, $result['total'], $command->page, $command->perPage);
    }
}
