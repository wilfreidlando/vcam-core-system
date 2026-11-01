<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §2 — what a started collection answers.
 *
 * The attempt itself, and what the payer must do next: the payment's
 * own action (a URL to open, an instruction to follow). The Core never
 * decides how the customer pays — it hands over what the provider
 * asked for.
 */
final readonly class PayInvoiceResult
{
    public function __construct(
        public InvoicePaymentView $payment,
        /** `true` when the payer still has to act (ADR-0038). */
        public bool $requiresAction = false,
        public ?string $paymentUrl = null,
        public ?string $actionHint = null,
    ) {}
}
