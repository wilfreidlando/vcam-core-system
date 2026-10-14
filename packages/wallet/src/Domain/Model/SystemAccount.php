<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Model;

/**
 * The ledger account kinds a wallet movement balances against (ADR-0065).
 * `clearing` and `withdrawal` are project-level accounts (reference `''`).
 *
 * - `clearing`: counterparty of value entering a wallet (a confirmed
 *   payment) and of an internal debit.
 * - `withdrawal`: counterparty of value leaving the Core's custody (a
 *   payout settled outside the Core, recorded with its proof).
 */
final class SystemAccount
{
    public const CLEARING = 'clearing';

    public const WITHDRAWAL = 'withdrawal';

    public const WALLET = 'wallet';

    public const PROJECT_LEVEL = '';
}
