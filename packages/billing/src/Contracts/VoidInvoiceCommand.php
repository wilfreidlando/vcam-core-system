<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §3 — the invoice to void.
 *
 * A void is the counter-passation of the issuance, so it is refused when
 * something has already been received: the money would have to be
 * returned, and that is a refund, not a void.
 */
final readonly class VoidInvoiceCommand
{
    /**
     * @param string $reason the motive of the cancellation — mandatory
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
