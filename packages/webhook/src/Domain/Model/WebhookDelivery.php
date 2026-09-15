<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

use DateTimeImmutable;

/**
 * One tracked delivery of one outbox event to one endpoint. `attempts`
 * counts completed attempts (not the one in flight); `nextRetryAt` is the
 * earliest time a further attempt is due — advisory only, since the actual
 * scheduling is Horizon's job `release()` (ADR-0040 §3), not a poller
 * reading this column.
 *
 * `replayedFromId` is null for every delivery the fan-out creates, and set
 * to the dead delivery's id for one created by `webhook:replay`
 * (ADR-0040 §7). The uniqueness that keeps the at-least-once relay
 * idempotent — `UNIQUE(endpoint_id, event_id)` — applies only among rows
 * where this is null; a replay is an intentional second row for the same
 * pair, not a duplicate.
 */
final readonly class WebhookDelivery
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $projectId,
        public string $eventId,
        public string $eventType,
        public array $payload,
        public WebhookDeliveryStatus $status,
        public int $attempts,
        public ?DateTimeImmutable $nextRetryAt,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?string $lastError,
        public ?DateTimeImmutable $deliveredAt,
        public ?string $replayedFromId = null,
    ) {}

    public function recordSuccess(DateTimeImmutable $at): self
    {
        return new self(
            $this->id,
            $this->endpointId,
            $this->projectId,
            $this->eventId,
            $this->eventType,
            $this->payload,
            WebhookDeliveryStatus::Delivered,
            $this->attempts + 1,
            null,
            $at,
            null,
            $at,
            $this->replayedFromId,
        );
    }

    /**
     * @param DateTimeImmutable|null $nextRetryAt null means the retry budget is
     *                                            exhausted — the delivery moves to `dead` (DLQ).
     */
    public function recordFailure(string $error, DateTimeImmutable $at, ?DateTimeImmutable $nextRetryAt): self
    {
        return new self(
            $this->id,
            $this->endpointId,
            $this->projectId,
            $this->eventId,
            $this->eventType,
            $this->payload,
            $nextRetryAt === null ? WebhookDeliveryStatus::Dead : WebhookDeliveryStatus::Pending,
            $this->attempts + 1,
            $nextRetryAt,
            $at,
            $error,
            null,
            $this->replayedFromId,
        );
    }
}
