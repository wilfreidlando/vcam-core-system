<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §2 — one attempt to collect an invoice online.
 *
 * The status is the canonical `InvoicePaymentStatus` value as a plain
 * string — never the enum itself (G3c): the enum lives in
 * `Domain/Model/`, out of reach of every other module.
 *
 * The amounts are in minor units (G5) and the dates are ISO 8601
 * strings, the convention of this module's contracts.
 */
final readonly class InvoicePaymentView
{
    public function __construct(
        public string $id,
        public string $invoiceId,
        public string $projectId,
        /** The opaque reference to the payment of the `payment` module. */
        public string $paymentId,
        /** 'pending' | 'succeeded' | 'failed' | 'canceled'. */
        public string $status,
        public int $amountMinor,
        public string $currency,
        /**
         * ADR-0094 §10 — what the provider confirmed it collected, when
         * the source confirmed an amount.
         */
        public ?int $confirmedAmountMinor = null,
        public ?string $failureReason = null,
        /** When the attempt settled — the money moved (ADR-0073 §2). */
        public ?string $settledAt = null,
        public ?string $createdAt = null,
    ) {}
}
