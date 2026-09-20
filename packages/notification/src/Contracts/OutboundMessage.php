<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * Command handed to a `NotificationChannelProvider` to send one message.
 *
 * `recipientRef` is `to_party_ref` verbatim (ADR-0041 §5) — a correlation
 * identifier, never a phone number or email (`parties` carries no PII,
 * G4). Resolving it to an actual delivery address is the adapter's job.
 *
 * `body` is the content supplied inline by the caller (ADR-0043 — no
 * template subsystem: `{"type": "text"|"image"|"file", ...}`),
 * unrendered and un-interpreted by the Core. Variable substitution and
 * the decision of *how* to send are entirely the adapter's job
 * (ADR-0042 §6).
 */
final readonly class OutboundMessage
{
    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $body
     */
    public function __construct(
        public string $appMessageRef,
        public NotificationChannel $channel,
        public string $recipientRef,
        public array $variables,
        public array $body,
        /**
         * Lets a multi-tenant-aware adapter scope a caller-supplied
         * session identifier (e.g. WAHA's `variables['session']`) to
         * this Project — security review finding: a caller-chosen
         * session name forwarded to the provider unscoped let one
         * Project hijack another Project's linked WhatsApp session by
         * guessing/supplying its name.
         */
        public string $projectId,
    ) {}
}
