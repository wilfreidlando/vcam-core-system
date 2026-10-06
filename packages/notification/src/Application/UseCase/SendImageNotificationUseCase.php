<?php

declare(strict_types=1);

namespace Core\Notification\Application\UseCase;

use Core\Notification\Application\Support\NotificationSendCascade;
use Core\Notification\Contracts\NotificationView;
use Core\Notification\Contracts\SendImageNotification;
use Core\Notification\Contracts\SendImageNotificationCommand;

final readonly class SendImageNotificationUseCase implements SendImageNotification
{
    public function __construct(
        private NotificationSendCascade $cascade,
    ) {}

    public function send(SendImageNotificationCommand $command): NotificationView
    {
        return $this->cascade->send(
            projectId: $command->projectId,
            channel: $command->channel,
            toPartyRef: $command->toPartyRef,
            variables: $command->variables,
            body: [
                'type' => 'image',
                'media_url' => $command->mediaUrl,
                'caption' => $command->caption,
                'filename' => $command->filename,
                'mimetype' => $command->mimetype,
            ],
            contentType: 'image',
            idempotencyKey: $command->idempotencyKey,
            environment: $command->environment,
        );
    }
}
