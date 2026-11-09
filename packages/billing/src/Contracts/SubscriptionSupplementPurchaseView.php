<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0117 §4 — what a purchase answers: the supplement, and the
 * invoice that carries it.
 *
 * The two travel together on purpose: the allowance is granted when that
 * invoice is paid, so the caller must know both.
 */
final readonly class SubscriptionSupplementPurchaseView
{
    public function __construct(
        public SubscriptionSupplementView $supplement,
        public InvoiceView $invoice,
    ) {}
}
