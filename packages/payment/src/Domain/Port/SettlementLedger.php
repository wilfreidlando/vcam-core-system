<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\ClearingReclassificationOutcome;
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
     *
     * The debit lands on the provider's own account — `clearing` with the
     * provider's code as its reference (ADR-0076 §2) — while the credit
     * stays project-level.
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
     *
     * A resolution that books no ledger account of its own — imputing the
     * money to a wallet, which the wallet module posts itself — writes
     * nothing here.
     */
    public function recordExceptionResolution(MoneyException $exception): void;

    /**
     * ADR-0076 §2 — the one-off reclassification that empties the
     * historical project-level `clearing` account into the provider's own
     * account, at deployment.
     *
     * `$amountMinor` is SIGNED: positive when the historical account holds
     * money the provider has (the money moves to the provider's account),
     * negative when it is owed to the project (the money moves back). The
     * implementation turns it into a balanced debit/credit pair.
     *
     * `$reason` is the MOTIVE of the reclassification (ADR-0076 §2: « une
     * écriture de reclassement unique, motivée et auditée »). It rides
     * in the transaction's free `external_ref` field: the ledger
     * transaction is immutable and chained by fingerprints (ADR-0081
     * S-16), so it IS the proof — the `payment` module may not depend on
     * the `audit` module (ModuleGraph, G3c).
     *
     * Idempotent on `(projectId, currency, providerCode)`: running it
     * again never moves the money twice — it reports `AlreadyPosted`, so
     * the caller can tell a no-op from a refusal.
     */
    public function recordClearingReclassification(
        string $projectId,
        string $providerCode,
        string $currency,
        int $amountMinor,
        string $reason,
    ): ClearingReclassificationOutcome;
}
