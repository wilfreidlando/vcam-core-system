<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §1 — the invoice to issue.
 *
 * The issuance is the moment the number is attributed and the document
 * is frozen (ADR-0094 §5).
 */
final readonly class IssueInvoiceCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $invoiceId,
        public string $actor,
        public ?string $correlationId = null,
    ) {}
}
