<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * The public-facing command — what a Project asks for when it sends a
 * notification (spec §13's `POST /v1/notifications` example). `toPartyRef`
 * is a correlation reference, never a contact address (ADR-0041 §5).
 */
final readonly class SendNotificationCommand
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public string $projectId,
        public NotificationChannel $channel,
        public string $toPartyRef,
        public string $template,
        public array $variables,
        public string $idempotencyKey,
    ) {}
}
