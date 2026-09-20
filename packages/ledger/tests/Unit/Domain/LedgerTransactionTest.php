<?php

declare(strict_types=1);

use Core\Ledger\Domain\Exception\NonPositiveLedgerEntryAmount;
use Core\Ledger\Domain\Exception\UnbalancedTransaction;
use Core\Ledger\Domain\Model\Direction;
use Core\Ledger\Domain\Model\LedgerEntry;
use Core\Ledger\Domain\Model\LedgerTransaction;
use Core\Support\Money\Money;

/**
 * @return list<LedgerEntry>
 */
function balancedEntries(int $amountMinor = 1_000, string $currency = 'XAF'): array
{
    return [
        new LedgerEntry('account-debit', Direction::Debit, Money::ofMinor($amountMinor, $currency)),
        new LedgerEntry('account-credit', Direction::Credit, Money::ofMinor($amountMinor, $currency)),
    ];
}

it('accepts a transaction whose entries balance to zero', function (): void {
    $transaction = new LedgerTransaction('tx-1', 'project-1', 'wallet_credit', null, 'idem-1', balancedEntries());

    expect($transaction->entries)->toHaveCount(2);
});

it('accepts a multi-leg transaction as long as the total balances', function (): void {
    $entries = [
        new LedgerEntry('a', Direction::Debit, Money::ofMinor(700, 'XAF')),
        new LedgerEntry('b', Direction::Debit, Money::ofMinor(300, 'XAF')),
        new LedgerEntry('c', Direction::Credit, Money::ofMinor(1_000, 'XAF')),
    ];

    $transaction = new LedgerTransaction('tx-1', 'project-1', 'fee_split', null, 'idem-1', $entries);

    expect($transaction->entries)->toHaveCount(3);
});

it('rejects fewer than two entries', function (): void {
    new LedgerTransaction('tx-1', 'project-1', 'wallet_credit', null, 'idem-1', [
        new LedgerEntry('account-debit', Direction::Debit, Money::ofMinor(1_000, 'XAF')),
    ]);
})->throws(InvalidArgumentException::class, 'at least two entries');

it('rejects entries that do not balance', function (): void {
    new LedgerTransaction('tx-1', 'project-1', 'wallet_credit', null, 'idem-1', [
        new LedgerEntry('account-debit', Direction::Debit, Money::ofMinor(1_000, 'XAF')),
        new LedgerEntry('account-credit', Direction::Credit, Money::ofMinor(999, 'XAF')),
    ]);
})->throws(UnbalancedTransaction::class, 'does not balance');

it('rejects entries mixing currencies', function (): void {
    new LedgerTransaction('tx-1', 'project-1', 'wallet_credit', null, 'idem-1', [
        new LedgerEntry('account-debit', Direction::Debit, Money::ofMinor(1_000, 'XAF')),
        new LedgerEntry('account-credit', Direction::Credit, Money::ofMinor(1_000, 'USD')),
    ]);
})->throws(InvalidArgumentException::class, 'cannot mix currencies');

it('rejects a zero-amount entry before it ever reaches the database CHECK constraint', function (): void {
    // Security review finding: this used to be caught only by
    // ledger_entries' CHECK (amount_minor > 0), surfacing as an
    // unhandled QueryException deep inside a transaction rather than a
    // clean rejection at construction.
    new LedgerTransaction('tx-1', 'project-1', 'wallet_credit', null, 'idem-1', [
        new LedgerEntry('account-debit', Direction::Debit, Money::zero('XAF')),
        new LedgerEntry('account-credit', Direction::Credit, Money::zero('XAF')),
    ]);
})->throws(NonPositiveLedgerEntryAmount::class, 'must have a positive amount');

it('rejects a negative-amount entry', function (): void {
    new LedgerTransaction('tx-1', 'project-1', 'wallet_credit', null, 'idem-1', [
        new LedgerEntry('account-debit', Direction::Debit, Money::ofMinor(-1_000, 'XAF')),
        new LedgerEntry('account-credit', Direction::Credit, Money::ofMinor(-1_000, 'XAF')),
    ]);
})->throws(NonPositiveLedgerEntryAmount::class, 'must have a positive amount');
