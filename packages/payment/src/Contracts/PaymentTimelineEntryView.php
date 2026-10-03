<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

final readonly class PaymentTimelineEntryView
{
    /**
     * @param string $type one of `Domain\Model\PaymentTimelineEntryType` values (raw string — G3c)
     * @param array<string, mixed> $details redacted
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $occurredAt,
        public string $summary,
        public array $details,
        public ?string $correlationId,
        public ?string $traceId,
    ) {}
}
