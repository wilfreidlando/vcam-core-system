<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0073 §1 — the life cycle of an invoice.
 *
 * - `draft` — the invoice is being written; it has no number yet;
 * - `open` — issued: it has a number, it is frozen, and it is due;
 * - `paid` — settled in full;
 * - `void` — cancelled before any payment; it is counter-passed;
 * - `uncollectible` — written off: the receivable became a bad debt.
 *
 * There is **no** "overdue" status: the lateness of a payment is the
 * `past_due` state of the contract (ADR-0072 §2), not a state of the
 * document.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    /**
     * ADR-0073 §1 — an issued invoice is frozen; only its status and
     * what has been paid of it move afterwards.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Void, self::Uncollectible => true,
            self::Draft, self::Open, self::Paid => false,
        };
    }
}
