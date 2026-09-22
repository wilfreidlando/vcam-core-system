<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use DateTimeImmutable;

/**
 * Broader than `DeadWebhookDeliveryView` (which only ever lists Dead rows):
 * this carries `status` and `deliveredAt` too, since `ListWebhookDeliveries`
 * lists across every status — an operator diagnosing a problem needs to see
 * what was actually executed (pending/delivered/dead), not only the dead
 * queue. `status` is the canonical `WebhookDeliveryStatus` enum's `->value`,
 * never the enum itself (G3c — same convention as `PaymentView::$status`).
 */
final readonly class WebhookDeliveryView
{
    /**
     * @param array<string, mixed> $payload the raw event payload — together with
     *                                      `eventId`/`eventType` this is everything
     *                                      `HttpWebhookSender` puts in the `{id, type,
     *                                      data}` envelope it actually sends; a
     *                                      consumer displaying "the request body"
     *                                      reconstructs that same shape from these
     *                                      three fields rather than a fourth,
     *                                      redundant stored copy.
     */
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $projectId,
        public string $eventId,
        public string $eventType,
        public array $payload,
        public string $status,
        public int $attempts,
        public ?string $lastError,
        public ?int $lastResponseStatus,
        public ?string $lastResponseBody,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?DateTimeImmutable $deliveredAt,
    ) {}
}
