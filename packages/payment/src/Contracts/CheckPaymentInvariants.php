<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * ADR-0078 §2 — the financial invariants `payment` owns, checked against
 * the ledger through its `Contracts/` only:
 *
 *  1. each project's trial balance nets to zero, per currency;
 *  2. every succeeded payment has its settlement transaction;
 *  3. no settlement transaction without a succeeded payment;
 *  4. the wallets of a project never hold more than what reached its
 *     clearing account (no value created).
 *
 * A violation opens a CRITICAL money exception (kind `invariant_violation`),
 * once — an unresolved one is not opened again on the next run. The check
 * only reads; it never corrects the ledger.
 */
interface CheckPaymentInvariants
{
    public function check(int $windowHours): PaymentInvariantReport;
}
