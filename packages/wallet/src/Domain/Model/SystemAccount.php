<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Model;

/**
 * The project-level ledger accounts a wallet movement balances against
 * (ADR-0065). Each is split by environment through its `reference`: `''`
 * in live — unchanged from Lot 2, so existing live postings keep their
 * account — and `sandbox` in sandbox, so a sandbox movement never lands in
 * a live account.
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

    public static function reference(string $environment): string
    {
        return $environment === 'sandbox' ? 'sandbox' : '';
    }
}
