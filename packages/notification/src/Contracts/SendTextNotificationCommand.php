<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Environment;

/**
 * `to_party_ref` is a correlation reference, never a contact address
 * (ADR-0041 §5) — the real destination lives in `variables['phone_number']`
 * (ADR-0042 §7).
 */
final readonly class SendTextNotificationCommand
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public string $projectId,
        public NotificationChannel $channel,
        public string $toPartyRef,
        public string $text,
        public array $variables,
        public string $idempotencyKey,
        // ADR-0060 — the environment of the calling API key.
        public Environment $environment = Environment::Live,
    ) {}
}
