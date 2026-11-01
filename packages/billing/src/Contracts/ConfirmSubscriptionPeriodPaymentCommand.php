<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §2 — the confirmation that the invoice of a period was paid.
 *
 * `seq` is the sequence number of the period being settled; `reference`
 * is the reference of the payment that settles it — the caller's own
 * reference, kept for the audit trail, never a credential.
 */
final readonly class ConfirmSubscriptionPeriodPaymentCommand
{
    /**
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public int $seq,
        public ?string $reference = null,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
