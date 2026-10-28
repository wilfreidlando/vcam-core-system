<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Payment\Contracts\PaymentChannel;

/**
 * ADR-0073 §2 — the collection of an invoice, asked for by the
 * project.
 *
 * The channel is the canonical `PaymentChannel` of the `payment` module
 * — the published vocabulary, never the provider's own name
 * (ADR-0038 §1).
 *
 * The payer's details are optional: the project's own customer record
 * carries them, and the Core prefers its own copy over what the caller
 * sends.
 */
final readonly class PayInvoiceCommand
{
    public function __construct(
        public string $projectId,
        public string $invoiceId,
        public PaymentChannel $channel,
        /** The HTTP `Idempotency-Key` of the request (G7). */
        public string $idempotencyKey,
        public ?string $customerPhoneNumber = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerLang = null,
        public ?string $returnUrl = null,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
