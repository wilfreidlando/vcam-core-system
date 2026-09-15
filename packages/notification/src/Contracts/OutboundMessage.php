<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Notification\NotificationTemplateKind;
use Core\Notification\NotificationTemplateStatus;

/**
 * Command handed to a `NotificationChannelProvider` to send one message.
 *
 * `recipientRef` is `to_party_ref` verbatim (ADR-0041 §5) — a correlation
 * identifier, never a phone number or email (`parties` carries no PII,
 * G4). Resolving it to an actual delivery address is the adapter's job.
 *
 * `templateKind`/`templateStatus` are carried through so a channel-aware
 * adapter (e.g. a future WhatsApp one) can decide how to send without the
 * Core hardcoding that channel's rules (ADR-0041 §4).
 */
final readonly class OutboundMessage
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public string $appMessageRef,
        public NotificationChannel $channel,
        public string $recipientRef,
        public string $templateKey,
        public int $templateVersion,
        public NotificationTemplateKind $templateKind,
        public NotificationTemplateStatus $templateStatus,
        public array $variables,
    ) {}
}
