<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0073 §4 — how the money of an offline receipt arrived.
 *
 * The register is **open** in principle (ADR-0073 §1) but the base
 * constrains it here (ADR-0094, « Alternatives écartées »): the method
 * decides the ledger account the receipt is booked on
 * (`manual_receipts:<moyen>`), and a typo in that field would book the
 * money on an account nobody reconciles.
 */
enum ManualReceiptMethod: string
{
    /** A bank transfer — the reference is the transfer's own. */
    case BankTransfer = 'bank_transfer';

    /** Cash, handed over at the counter. */
    case Cash = 'cash';

    /** A cheque. */
    case Cheque = 'cheque';

    /** Anything else, with a mandatory reference. */
    case Other = 'other';
}
