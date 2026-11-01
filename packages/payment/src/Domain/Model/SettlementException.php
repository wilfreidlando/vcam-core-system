<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * Why a settled payment could not be allocated automatically (ADR-0077 §2).
 *
 * The money is real — the provider collected it — so the payment still
 * reaches `succeeded`; what changes is where its settlement is booked:
 * the `payment_exceptions` ledger account, never a wallet or an invoice,
 * until an operator resolves it.
 */
enum SettlementException: string
{
    /** The provider confirmed a different amount than the one requested. */
    case AmountMismatch = 'amount_mismatch';

    /** The provider confirmed a different currency than the one requested. */
    case CurrencyMismatch = 'currency_mismatch';
}
