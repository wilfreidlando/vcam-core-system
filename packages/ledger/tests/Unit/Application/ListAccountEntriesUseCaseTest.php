<?php

declare(strict_types=1);

use Core\Ledger\Application\UseCase\ListAccountEntriesUseCase;
use Core\Ledger\Contracts\ListAccountEntriesCommand;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\Direction;
use Core\Ledger\Domain\Model\LedgerAccount;
use Core\Ledger\Domain\Model\PostedLedgerEntry;
use Core\Ledger\Tests\Unit\Fakes\InMemoryLedgerAccountRepository;
use Core\Ledger\Tests\Unit\Fakes\InMemoryLedgerEntryRepository;
use Core\Support\Money\Money;

it('returns an empty page for an account that was never created', function (): void {
    $useCase = new ListAccountEntriesUseCase(new InMemoryLedgerAccountRepository, new InMemoryLedgerEntryRepository);

    $page = $useCase->list(new ListAccountEntriesCommand('project-1', 'wallet', 'wallet-1', 'XAF'));

    expect($page->items)->toBe([])
        ->and($page->total)->toBe(0);
});

it('lists an existing accounts entries with their transaction context', function (): void {
    $accounts = new InMemoryLedgerAccountRepository;
    $account = new LedgerAccount('account-1', 'project-1', AccountKind::fromString('wallet'), 'wallet-1', 'XAF');
    $accounts->save($account);

    $entries = new InMemoryLedgerEntryRepository;
    $entries->put('account-1', new PostedLedgerEntry(
        id: 1,
        transactionId: 'txn-1',
        transactionType: 'wallet_credit',
        externalRef: 'payment-1',
        direction: Direction::Credit,
        amount: Money::ofMinor(5_000, 'XAF'),
        createdAt: new DateTimeImmutable('2026-09-22T10:00:00Z'),
    ));

    $useCase = new ListAccountEntriesUseCase($accounts, $entries);

    $page = $useCase->list(new ListAccountEntriesCommand('project-1', 'wallet', 'wallet-1', 'XAF'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->transactionType)->toBe('wallet_credit')
        ->and($page->items[0]->externalRef)->toBe('payment-1')
        ->and($page->items[0]->direction)->toBe('credit')
        ->and($page->items[0]->amountMinor)->toBe(5_000)
        ->and($page->items[0]->currency)->toBe('XAF');
});
