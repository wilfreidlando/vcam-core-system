<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §3 — the invoice to write off.
 *
 * A write-off is an accounting act, never an effect of the time
 * (ADR-0094 §12): it is refused by an operator, with a motive, and it
 * leaves a trace.
 */
final readonly class MarkInvoiceUncollectibleCommand
{
    /**
     * @param string $reason the motive of the write-off — mandatory
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $invoiceId,
        public string $reason,
        public string $actor,
        public ?string $correlationId = null,
    ) {}
}
