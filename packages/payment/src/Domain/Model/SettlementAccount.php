<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * Ledger account kinds a payment settlement is booked against
 * (ADR-0076 §2, §3). All are project-level (reference `''`).
 *
 * `CLEARING` must stay equal to `Core\Wallet\Domain\Model\SystemAccount::CLEARING`:
 * a wallet credit and a direct settlement debit the same account — what
 * the provider holds for the project. A test pins the two together, since
 * neither module may import the other's Domain (G3c).
 */
final class SettlementAccount
{
    /** What the provider holds for the project (asset). */
    public const CLEARING = 'clearing';

    /** Project revenue: a direct collection, with no wallet nor invoice to credit. */
    public const REVENUE = 'revenue';

    /** Money collected that cannot be allocated automatically (ADR-0077). */
    public const PAYMENT_EXCEPTIONS = 'payment_exceptions';

    /**
     * Value leaving the platform's books, settled outside it (a refund
     * made by hand). Must stay equal to `Core\Wallet\Domain\Model\SystemAccount::WITHDRAWAL`.
     */
    public const WITHDRAWAL = 'withdrawal';

    public const PROJECT_LEVEL = '';
}
