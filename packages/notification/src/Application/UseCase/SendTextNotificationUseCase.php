<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Contracts\SendTextNotification;
use Core\Notification\Contracts\SendTextNotificationCommand;

final readonly class SendTextNotificationUseCase implements SendTextNotification
{
    public function __construct(
        private NotificationSendCascade $cascade,
    ) {}

    public function send(SendTextNotificationCommand $command): NotificationView
    {
        return $this->cascade->send(
            projectId: $command->projectId,
            channel: $command->channel,
            toPartyRef: $command->toPartyRef,
            variables: $command->variables,
            body: ['type' => 'text', 'text' => $command->text],
            contentType: 'text',
            idempotencyKey: $command->idempotencyKey,
            environment: $command->environment,
        );
    }
}
