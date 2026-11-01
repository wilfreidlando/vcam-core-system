<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

/**
 * Where the money that credits a wallet comes from — a CLOSED set
 * (ADR-0076 §2, ADR-0077 §4).
 *
 * The wallet module knows nothing about providers nor about payments; it
 * only knows the ledger account the value is debited from. This enum is
 * that knowledge, and it is deliberately an enum and not a free string:
 * a caller able to name an arbitrary account kind could credit a wallet
 * from `wallet` itself — creating value out of nothing (ADR-0078 §2,
 * invariant 4). Two sources are legitimate, and only two:
 *
 * - `Clearing`: the money the provider holds for the project. The
 *   provider's code rides along in `WalletCreditOrigin` so the debit
 *   lands on `clearing:<prestataire>` (ADR-0076 §2) — the account the
 *   reconciliation compares with the provider's statement (ADR-0078 §3).
 * - `PaymentExceptions`: money collected but not allocable (ADR-0077 §3),
 *   later imputed to a wallet by an operator (ADR-0077 §4).
 *
 * `Clearing` must stay equal to `Core\Wallet\Domain\Model\SystemAccount::CLEARING`
 * and `PaymentExceptions` to `Core\Payment\Domain\Model\SettlementAccount::PAYMENT_EXCEPTIONS`:
 * the three modules may not import each other's Domain (G3c), so a test
 * pins the values together instead.
 */
enum WalletCreditSource: string
{
    /** The provider holds the money for the project (asset `clearing`). */
    case Clearing = 'clearing';

    /** Money collected but not allocable, waiting in the exception pool. */
    case PaymentExceptions = 'payment_exceptions';
}
