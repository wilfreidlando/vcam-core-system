<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

final readonly class SendSystemNotificationCommand
{
    /**
     * @param string $toPartyRef a correlation reference, never a contact
     *                           address (ADR-0041 §5) — the real
     *                           destination is `$phoneNumber`
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public string $projectId,
        public NotificationChannel $channel,
        public string $toPartyRef,
        public string $text,
        public string $phoneNumber,
        public string $idempotencyKey,
        public array $variables = [],
    ) {}
}
