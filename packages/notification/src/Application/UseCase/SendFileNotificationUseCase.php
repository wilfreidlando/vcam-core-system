<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Contracts\SendFileNotification;
use Core\Notification\Contracts\SendFileNotificationCommand;

final readonly class SendFileNotificationUseCase implements SendFileNotification
{
    public function __construct(
        private NotificationSendCascade $cascade,
    ) {}

    public function send(SendFileNotificationCommand $command): NotificationView
    {
        return $this->cascade->send(
            projectId: $command->projectId,
            channel: $command->channel,
            toPartyRef: $command->toPartyRef,
            variables: $command->variables,
            body: [
                'type' => 'file',
                'media_url' => $command->mediaUrl,
                'filename' => $command->filename,
                'mimetype' => $command->mimetype,
                'caption' => $command->caption,
            ],
            contentType: 'file',
            idempotencyKey: $command->idempotencyKey,
        );
    }
}
