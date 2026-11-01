<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0073 §2, §4 — one settlement of a customer: what it paid, against
 * which invoice, how, and when.
 *
 * The two kinds of settlement — the online attempt (`invoice_payments`)
 * and the offline receipt (`manual_receipts`) — are read in **one**
 * list: the operator's question is « what did this customer pay me? »,
 * and its answer is a chronology, not two tables.
 *
 * The `id` is unique across both kinds: it carries its prefix
 * (`payment:<uuid>` / `receipt:<uuid>`), because the two tables have
 * their own identifiers and the console must be able to tell the two
 * rows apart.
 *
 * The amount is in minor units (G5) and the date an ISO 8601 string,
 * the convention of this module's contracts.
 */
final readonly class CustomerSettlementView
{
    public function __construct(
        /** `payment:<uuid>` or `receipt:<uuid>` — unique across both kinds. */
        public string $id,
        /** 'payment' | 'receipt'. */
        public string $kind,
        public string $invoiceId,
        /** The number of the invoice — `null` while it is still a draft. */
        public ?string $invoiceNumber,
        /** 'online' for a payment, the receipt's own method otherwise. */
        public string $method,
        /** The receipt's reference, or the id of the payment. */
        public string $reference,
        public int $amountMinor,
        public string $currency,
        /** The canonical status of the settlement, as a plain string (G3c). */
        public string $status,
        /** When the settlement happened — the newest first. */
        public string $occurredAt,
    ) {}
}
