<?php

declare(strict_types=1);

namespace Core\Events\Contracts;

use DateTimeImmutable;

/**
 * One fact worth telling other systems about (spec technique §12.2, e.g.
 * `wallet.credited`, `payment.completed`). Like `Core\Audit\Contracts\AuditEntry`,
 * this is the module's entire model for now — no separate Domain layer,
 * because the only rule this module currently enforces is "written in the
 * same transaction as the state it describes" (ADR-0032), not a business
 * rule about the event itself.
 */
final readonly class DomainEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param bool $skipWebhookEndpointFanOut A publisher-set hint, never a
     *                                        blanket "skip every consumer" signal: true means this
     *                                        event already has its own dedicated, point-to-point
     *                                        delivery arranged elsewhere (e.g. a payment's own
     *                                        `callback_url`), so the `webhook` module's generic
     *                                        Project-wide endpoint fan-out should skip relaying it —
     *                                        a future `notification`/`billing` listener on this same
     *                                        event type is unaffected, since each consumer decides
     *                                        independently whether this flag means anything to it.
     *                                        Defaults `false` (relay to every subscribed consumer, the
     *                                        pre-existing behaviour) so every other publisher is
     *                                        unchanged.
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $type,
        public array $payload,
        public ?string $correlationId,
        public DateTimeImmutable $occurredAt,
        public bool $skipWebhookEndpointFanOut = false,
        // ADR-0060 — `live` or `sandbox`: consumers (webhook fan-out) only
        // deliver to targets of the same environment. Null = live (every
        // publisher written before environments existed).
        public ?string $environment = null,
    ) {}
}
