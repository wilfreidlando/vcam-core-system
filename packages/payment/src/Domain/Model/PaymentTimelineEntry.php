<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use DateTimeImmutable;

/**
 * One step of a payment's journey (ADR-0059). `details` is already
 * redacted — secrets never reach it. The correlation / trace ids of the
 * request or job that produced it are attached by the persistence adapter,
 * not by the Domain (they are an infrastructure concern).
 */
final readonly class PaymentTimelineEntry
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $id,
        public string $paymentId,
        public string $projectId,
        public PaymentTimelineEntryType $type,
        public DateTimeImmutable $occurredAt,
        public string $summary,
        public array $details = [],
        public ?string $correlationId = null,
        public ?string $traceId = null,
    ) {}
}
