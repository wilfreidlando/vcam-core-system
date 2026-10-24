<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\Payment;

/**
 * Books a payment's settlement in the ledger when no wallet is credited
 * (ADR-0076 §3: every confirmed payment is written, without exception).
 *
 * The wallet-credit path keeps going through `Core\Wallet\Contracts\CreditWallet`,
 * which books `clearing → wallet` itself. Implementations use the ledger's
 * `Contracts/` only, and key the ledger transaction on the payment id: a
 * payment has exactly one settlement transaction, whichever path booked it,
 * and a replay never books a second one.
 */
interface SettlementLedger
{
    /**
     * Debit `clearing`, credit `revenue`, for the payment's own amount and
     * currency — a direct collection in the project's name.
     */
    public function recordDirectSettlement(Payment $payment): void;

    /**
     * Debit `clearing`, credit `payment_exceptions`, for what the provider
     * actually confirmed (ADR-0077 §2).
     *
     * Returns `false` without booking anything when `$currency` is not a
     * currency the ledger supports — the exception is still recorded on the
     * payment, and an operator resolves it by hand.
     */
    public function recordUnallocatedSettlement(Payment $payment, int $amountMinor, string $currency): bool;

    /**
     * ADR-0077 §4 — moves an exception's `bookedAmount` out of
     * `payment_exceptions` into the account its resolution names
     * (`withdrawal` for a refund made by hand, `revenue`). Idempotent on
     * the exception id: approving twice never books twice.
     */
    public function recordExceptionResolution(MoneyException $exception): void;
}
