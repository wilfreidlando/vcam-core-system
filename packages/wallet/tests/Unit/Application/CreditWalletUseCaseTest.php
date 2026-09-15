<?php

declare(strict_types=1);

use Core\Ledger\Contracts\Direction;
use Core\Wallet\Application\UseCase\CreditWalletUseCase;
use Core\Wallet\Contracts\CreditWalletCommand;
use Core\Wallet\Tests\Unit\Fakes\FakeAccountResolver;
use Core\Wallet\Tests\Unit\Fakes\FakeLedgerPoster;
use Core\Wallet\Tests\Unit\Fakes\FixedClock;
use Core\Wallet\Tests\Unit\Fakes\InMemoryEventOutbox;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletBalanceRepository;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletRepository;
use Core\Wallet\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Wallet\Tests\Unit\Fakes\SequentialIdentifier;

/**
 * @return array{0: CreditWalletUseCase, 1: InMemoryWalletRepository, 2: InMemoryWalletBalanceRepository, 3: FakeLedgerPoster, 4: InMemoryEventOutbox}
 */
function makeCreditUseCase(): array
{
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $ledgerPoster = new FakeLedgerPoster;
    $outbox = new InMemoryEventOutbox;

    $useCase = new CreditWalletUseCase(
        new PassthroughUnitOfWork,
        $wallets,
        $balances,
        new FakeAccountResolver,
        $ledgerPoster,
        $outbox,
        new SequentialIdentifier,
        new FixedClock,
    );

    return [$useCase, $wallets, $balances, $ledgerPoster, $outbox];
}

it('lazily creates the wallet on its first credit', function (): void {
    [$useCase, $wallets, $balances] = makeCreditUseCase();

    $view = $useCase->credit(new CreditWalletCommand('project-1', 'party-1', 1_000, 'XAF', null, 'idem-1'));

    expect($wallets->count())->toBe(1)
        ->and($view->availableMinor)->toBe(1_000)
        ->and($view->currency)->toBe('XAF')
        ->and($balances->find($view->walletId)?->available->amountMinor())->toBe(1_000);
});

it('reuses the existing wallet for the same party and currency', function (): void {
    [$useCase, $wallets] = makeCreditUseCase();

    $first = $useCase->credit(new CreditWalletCommand('project-1', 'party-1', 1_000, 'XAF', null, 'idem-1'));
    $second = $useCase->credit(new CreditWalletCommand('project-1', 'party-1', 500, 'XAF', null, 'idem-2'));

    expect($second->walletId)->toBe($first->walletId)
        ->and($second->availableMinor)->toBe(1_500)
        ->and($wallets->count())->toBe(1);
});

it('posts a balanced double-entry transaction: debit clearing, credit wallet', function (): void {
    [$useCase, , , $ledgerPoster] = makeCreditUseCase();

    $useCase->credit(new CreditWalletCommand('project-1', 'party-1', 1_000, 'XAF', null, 'idem-1'));

    expect($ledgerPoster->posted)->toHaveCount(1);

    $entries = $ledgerPoster->posted[0]->entries;
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->direction)->toBe(Direction::Debit)
        ->and($entries[1]->direction)->toBe(Direction::Credit)
        ->and($entries[0]->amountMinor)->toBe($entries[1]->amountMinor);
});

it('appends a wallet.credited event to the outbox', function (): void {
    [$useCase, , , , $outbox] = makeCreditUseCase();

    $view = $useCase->credit(new CreditWalletCommand('project-1', 'party-1', 1_000, 'XAF', 'ext-ref', 'idem-1'));

    expect($outbox->events)->toHaveCount(1)
        ->and($outbox->events[0]->type)->toBe('wallet.credited')
        ->and($outbox->events[0]->payload['wallet_id'])->toBe($view->walletId)
        ->and($outbox->events[0]->payload['amount_minor'])->toBe(1_000);
});

it('replaying the same idempotency key does not double the balance or the outbox', function (): void {
    [$useCase, , $balances, , $outbox] = makeCreditUseCase();

    $first = $useCase->credit(new CreditWalletCommand('project-1', 'party-1', 1_000, 'XAF', null, 'idem-1'));
    $second = $useCase->credit(new CreditWalletCommand('project-1', 'party-1', 1_000, 'XAF', null, 'idem-1'));

    expect($second->availableMinor)->toBe($first->availableMinor)
        ->and($balances->find($first->walletId)?->available->amountMinor())->toBe(1_000)
        ->and($outbox->events)->toHaveCount(1);
});
