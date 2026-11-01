<?php

declare(strict_types=1);

use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Support\Money\Money;
use Core\Wallet\Application\UseCase\DebitWalletUseCase;
use Core\Wallet\Contracts\DebitWalletCommand;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Domain\Exception\WalletProjectMismatch;
use Core\Wallet\Domain\Model\SystemAccount;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Tests\Unit\Fakes\FakeAccountResolver;
use Core\Wallet\Tests\Unit\Fakes\FakeLedgerPoster;
use Core\Wallet\Tests\Unit\Fakes\FixedClock;
use Core\Wallet\Tests\Unit\Fakes\InMemoryEventOutbox;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletBalanceRepository;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletRepository;
use Core\Wallet\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Wallet\Tests\Unit\Fakes\SequentialIdentifier;

/**
 * @return array{0: DebitWalletUseCase, 1: InMemoryWalletBalanceRepository, 2: FakeLedgerPoster, 3: InMemoryEventOutbox, 4: string, 5: FakeAccountResolver}
 */
function makeDebitUseCase(int $initialAvailableMinor = 1_000, string $currency = 'XAF'): array
{
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $ledgerPoster = new FakeLedgerPoster;
    $outbox = new InMemoryEventOutbox;
    $accounts = new FakeAccountResolver;

    $wallet = new Wallet('wallet-1', 'project-1', 'party-1', $currency, 'active');
    $wallets->save($wallet);
    $balances->save(new WalletBalance('wallet-1', Money::ofMinor($initialAvailableMinor, $currency), Money::zero($currency), 0));

    $useCase = new DebitWalletUseCase(
        new PassthroughUnitOfWork,
        $wallets,
        $balances,
        $accounts,
        $ledgerPoster,
        $outbox,
        new SequentialIdentifier,
        new FixedClock,
    );

    return [$useCase, $balances, $ledgerPoster, $outbox, $wallet->id, $accounts];
}

/**
 * The account the debit CREDITS — the one that is not the wallet's own
 * account, as the ledger resolver was asked for it.
 */
function counterpartyAccount(FakeAccountResolver $accounts): ResolveOrCreateAccountCommand
{
    foreach ($accounts->calls as $call) {
        if ($call->kind !== SystemAccount::WALLET) {
            return $call;
        }
    }

    throw new RuntimeException('No counterparty account was resolved.');
}

it('debits an existing wallet with sufficient funds', function (): void {
    [$useCase, $balances, , , $walletId] = makeDebitUseCase(1_000);

    $view = $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));

    expect($view->availableMinor)->toBe(600)
        ->and($balances->find($walletId)?->available->amountMinor())->toBe(600);
});

it('rejects a debit against an unknown wallet', function (): void {
    [$useCase] = makeDebitUseCase();

    $useCase->debit(new DebitWalletCommand('project-1', 'unknown-wallet', 100, null, 'idem-1'));
})->throws(WalletNotFound::class);

it('rejects a debit claiming a project that does not own the wallet', function (): void {
    [$useCase, , , , $walletId] = makeDebitUseCase(1_000);

    $useCase->debit(new DebitWalletCommand('project-2', $walletId, 400, null, 'idem-1'));
})->throws(WalletProjectMismatch::class);

it('rejects a debit that would overdraw the available balance', function (): void {
    [$useCase, , , , $walletId] = makeDebitUseCase(100);

    $useCase->debit(new DebitWalletCommand('project-1', $walletId, 101, null, 'idem-1'));
})->throws(InsufficientFunds::class);

it('posts a balanced double-entry transaction: debit wallet, credit withdrawal', function (): void {
    [$useCase, , $ledgerPoster, , $walletId] = makeDebitUseCase(1_000);

    $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));

    expect($ledgerPoster->posted)->toHaveCount(1);

    $entries = $ledgerPoster->posted[0]->entries;
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->direction)->toBe(Direction::Debit)
        ->and($entries[1]->direction)->toBe(Direction::Credit)
        ->and($entries[0]->amountMinor)->toBe($entries[1]->amountMinor);
});

/**
 * ADR-0076 §2 — the money that leaves a wallet leaves the books with it:
 * its counterparty is the project's `withdrawal` account, the one that
 * carries the recorded exits. It is NOT `clearing`, which is what the
 * provider holds for the project: a wallet debit would inflate the
 * provider's account with money it never held, and leave the historical
 * project-level account with a balance no settlement explains.
 */
it('credits the project withdrawal account, never the clearing account (ADR-0076 §2)', function (): void {
    [$useCase, , , , $walletId, $accounts] = makeDebitUseCase(1_000);

    $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));

    $counterparty = counterpartyAccount($accounts);

    $kinds = [];
    foreach ($accounts->calls as $call) {
        $kinds[] = $call->kind;
    }

    expect($counterparty->kind)->toBe(SystemAccount::WITHDRAWAL)
        ->and($counterparty->reference)->toBe(SystemAccount::PROJECT_LEVEL)
        // No clearing account is even opened by a debit.
        ->and($kinds)->not->toContain(SystemAccount::CLEARING);
});

it('appends a wallet.debited event to the outbox', function (): void {
    [$useCase, , , $outbox, $walletId] = makeDebitUseCase(1_000);

    $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));

    expect($outbox->events)->toHaveCount(1)
        ->and($outbox->events[0]->type)->toBe('wallet.debited')
        ->and($outbox->events[0]->payload['amount_minor'])->toBe(400);
});

it('replaying the same idempotency key does not double-debit or append a second event', function (): void {
    [$useCase, $balances, , $outbox, $walletId] = makeDebitUseCase(1_000);

    $first = $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));
    $second = $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));

    expect($second->availableMinor)->toBe($first->availableMinor)
        ->and($balances->find($walletId)?->available->amountMinor())->toBe(600)
        ->and($outbox->events)->toHaveCount(1);
});

/**
 * The bug this test exists to pin down (see DebitWalletUseCase): on replay,
 * the balance already reflects the ORIGINAL successful debit. A replay of a
 * request that exactly exhausted the wallet must still succeed as a no-op —
 * re-evaluating "sufficient funds" against the already-reduced balance would
 * wrongly reject a request that had already succeeded once. Idempotency must
 * be checked before the funds check, not after.
 */
it('replays successfully even when the reduced balance would no longer cover the original amount', function (): void {
    [$useCase, $balances, , , $walletId] = makeDebitUseCase(400);

    $first = $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));
    expect($first->availableMinor)->toBe(0);

    // A naive "check funds first" implementation would throw InsufficientFunds
    // here, because the balance is now 0 and the request asks for 400 again.
    $second = $useCase->debit(new DebitWalletCommand('project-1', $walletId, 400, null, 'idem-1'));

    expect($second->availableMinor)->toBe(0)
        ->and($balances->find($walletId)?->available->amountMinor())->toBe(0);
});
