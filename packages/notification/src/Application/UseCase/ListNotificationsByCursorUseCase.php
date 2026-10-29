<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Contracts\ListNotificationsByCursor;
use Core\Notification\Contracts\ListNotificationsByCursorCommand;
use Core\Notification\Contracts\NotificationCursorPage;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;
use Core\Support\Access\AccessScope;
use DateTimeInterface;

final readonly class ListNotificationsByCursorUseCase implements ListNotificationsByCursor
{
    private const MAX_LIMIT = 100;

    public function __construct(
        private NotificationRepository $notifications,
    ) {}

    public function list(ListNotificationsByCursorCommand $command, AccessScope $scope): NotificationCursorPage
    {
        // ADR-0069 §2 — a project outside the scope yields nothing rather
        // than another tenant's notifications.
        if (! $scope->allowsProject($command->projectId)) {
            return new NotificationCursorPage([]);
        }

        $page = $this->notifications->listByCursor(
            $command->projectId,
            $command->status !== null ? NotificationStatus::tryFrom($command->status) : null,
            $command->channel,
            $command->createdFrom,
            $command->createdTo,
            $command->cursor,
            max(1, min(self::MAX_LIMIT, $command->limit)),
        );

        return new NotificationCursorPage(
            array_map(
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
                $page['items'],
            ),
            $page['nextCursor'],
        );
    }
}
