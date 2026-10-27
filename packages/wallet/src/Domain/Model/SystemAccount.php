<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Model;

/**
 * The ledger account kinds a wallet movement balances against (ADR-0065,
 * ADR-0076 §2).
 *
 * - `wallet`: the wallet's own account, one per party — the reference is
 *   the wallet's id. A liability: the money held for that party.
 * - `clearing`: what the provider holds for the project. Since ADR-0076
 *   §2 it is PER PROVIDER — the reference is the provider's code, the
 *   account being `clearing:<prestataire>`. The historical project-level
 *   account (reference `''`) survives only for the money booked before
 *   that rule, and `payment:reclassify-clearing` moves it over, once,
 *   motivated and audited.
 * - `withdrawal`: the project-level account (reference `''`) of the
 *   recorded exits — the counterparty of a withdrawal recorded by
 *   `RecordWithdrawalUseCase` and of a wallet debit. The money that
 *   leaves a wallet leaves the books with it; it does NOT belong to the
 *   provider's account.
 */
final class SystemAccount
{
    public const CLEARING = 'clearing';

    public const WITHDRAWAL = 'withdrawal';

    public const WALLET = 'wallet';

    public const PROJECT_LEVEL = '';
}
