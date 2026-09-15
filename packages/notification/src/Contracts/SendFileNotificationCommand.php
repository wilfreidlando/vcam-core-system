<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class SendFileNotificationCommand
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public string $projectId,
        public NotificationChannel $channel,
        public string $toPartyRef,
        public string $mediaUrl,
        public string $filename,
        public string $mimetype,
        public ?string $caption,
        public array $variables,
        public string $idempotencyKey,
    ) {}
}
