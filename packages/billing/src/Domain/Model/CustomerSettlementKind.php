<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

/**
 * ADR-0073 §2, §4 — the two kinds of settlement a customer may have.
 *
 * The online attempt (`invoice_payments`) and the offline receipt
 * (`manual_receipts`) live in two tables with two vocabularies; the
 * operator reads them in one list, so the list says which is which.
 */
enum CustomerSettlementKind: string
{
    case Payment = 'payment';
    case Receipt = 'receipt';
}
