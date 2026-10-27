<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

use InvalidArgumentException;

/**
 * Where a wallet credit comes from, and — for the money a provider holds
 * — WHICH provider holds it (ADR-0076 §2).
 *
 * The wallet module never learns who a provider is: it only carries the
 * provider's code as the *reference* of the ledger account it debits, so
 * the money lands on `clearing:<prestataire>` instead of the historical
 * project-level `clearing` (reference `''`). The reconciliation (ADR-0078
 * §3/§4) then compares that account with the provider's statement, and
 * the payouts (ADR-0079) can isolate a provider.
 *
 * The combination is checked at construction: a `Clearing` origin without
 * a provider code would debit the historical project-level account (the
 * very thing ADR-0076 §2 replaces), and a `PaymentExceptions` origin
 * carrying a provider code would name a provider that holds nothing.
 * Both are refused rather than silently corrected.
 */
final readonly class WalletCreditOrigin
{
    /**
     * The provider's code, when the source is `Clearing`; `null` otherwise.
     */
    public ?string $providerCode;

    public function __construct(
        public WalletCreditSource $source,
        ?string $providerCode = null,
    ) {
        $code = $providerCode === null ? null : trim($providerCode);

        if ($source === WalletCreditSource::Clearing && ($code === null || $code === '')) {
            throw new InvalidArgumentException(
                'A Clearing origin must name the provider holding the money (its code), so the debit lands on clearing:<provider>.'
            );
        }

        if ($source === WalletCreditSource::PaymentExceptions && $code !== null && $code !== '') {
            throw new InvalidArgumentException(
                'A PaymentExceptions origin names no provider: the money waits in the exception pool, held by no provider.'
            );
        }

        $this->providerCode = $code;
    }

    /**
     * The reference of the ledger account to debit: the provider's code
     * for `Clearing`, the project-level reference (`''`) for
     * `PaymentExceptions`.
     */
    public function accountReference(): string
    {
        return $this->source === WalletCreditSource::Clearing
            ? ($this->providerCode ?? '')
            : '';
    }
}
