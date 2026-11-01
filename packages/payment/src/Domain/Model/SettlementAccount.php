<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use InvalidArgumentException;

/**
 * Ledger account kinds a payment settlement is booked against
 * (ADR-0076 §2, §3).
 *
 * Accounts are cloisonnés by `(project_id, kind, reference, currency)`:
 * a KIND says what the account is, a REFERENCE says which instance of it
 * the money sits on. Three of the kinds below are project-level
 * (reference `''`); `clearing` is NOT any more — it is per provider, its
 * reference being the provider's code (ADR-0076 §2: « les nouvelles
 * écritures utilisent `clearing:<prestataire>` pour permettre le
 * rapprochement par prestataire »). The historical project-level
 * `clearing` (reference `''`) is kept — it still holds the money booked
 * before that rule, and `payment:reclassify-clearing` moves it over,
 * once, motivated and audited.
 *
 * `CLEARING` must stay equal to `Core\Wallet\Domain\Model\SystemAccount::CLEARING`:
 * a wallet credit and a direct settlement debit the same account — what
 * the provider holds for the project. A test pins the two together, since
 * neither module may import the other's Domain (G3c).
 */
final class SettlementAccount
{
    /** What the provider holds for the project (asset), per provider. */
    public const CLEARING = 'clearing';

    /** Project revenue: a direct collection, with no wallet nor invoice to credit. */
    public const REVENUE = 'revenue';

    /**
     * Money collected for a customer, waiting to be allocated onto its
     * receivable (ADR-0076 §3, amended by ADR-0094 §9): the CREDIT of an
     * invoice payment lands here, and `billing` later moves it onto the
     * customer's receivable account.
     *
     * Project-level (reference `''`): the money is not attributed to a
     * customer until `billing` allocates it.
     */
    public const COLLECTIONS = 'collections';

    /** Money collected that cannot be allocated automatically (ADR-0077). */
    public const PAYMENT_EXCEPTIONS = 'payment_exceptions';

    /**
     * Value leaving the platform's books, settled outside it (a refund
     * made by hand). Must stay equal to `Core\Wallet\Domain\Model\SystemAccount::WITHDRAWAL`.
     */
    public const WITHDRAWAL = 'withdrawal';

    /** The reference of a project-level account — the one with no instance. */
    public const PROJECT_LEVEL = '';

    /**
     * The reference of the `clearing` account of one provider (ADR-0076
     * §2): the provider's code, so the account is `clearing:<prestataire>`.
     *
     * A blank provider code would silently fall back to the historical
     * project-level account — the exact ambiguity ADR-0076 §2 removes —
     * so it is refused here, once, for every caller.
     */
    public static function clearingReferenceFor(string $providerCode): string
    {
        $reference = trim($providerCode);

        if ($reference === '') {
            throw new InvalidArgumentException(
                'A settlement reference must name the provider holding the money: a blank code would fall back to the historical project-level clearing account.'
            );
        }

        return $reference;
    }
}
