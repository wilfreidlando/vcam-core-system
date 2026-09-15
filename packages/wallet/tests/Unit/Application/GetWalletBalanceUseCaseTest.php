<?php

declare(strict_types=1);

use Core\Support\Money\Money;
use Core\Wallet\Application\UseCase\GetWalletBalanceUseCase;
use Core\Wallet\Contracts\GetWalletBalanceCommand;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletBalanceRepository;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletRepository;

it('returns the balance for a wallet owned by the requesting project', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $wallets->save(new Wallet('wallet-1', 'project-1', 'party-1', 'XAF', 'active'));
    $balances->save(new WalletBalance('wallet-1', Money::ofMinor(1_000, 'XAF'), Money::zero('XAF'), 3));

    $useCase = new GetWalletBalanceUseCase($wallets, $balances);
    $view = $useCase->get(new GetWalletBalanceCommand('wallet-1', 'project-1'));

    expect($view->walletId)->toBe('wallet-1')
        ->and($view->availableMinor)->toBe(1_000)
        ->and($view->currency)->toBe('XAF');
});

it('rejects an unknown wallet id', function (): void {
    $useCase = new GetWalletBalanceUseCase(new InMemoryWalletRepository, new InMemoryWalletBalanceRepository);

    $useCase->get(new GetWalletBalanceCommand('unknown', 'project-1'));
})->throws(WalletNotFound::class);

it('rejects a wallet that belongs to another project, with the same exception as not-found', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $wallets->save(new Wallet('wallet-1', 'project-1', 'party-1', 'XAF', 'active'));
    $balances->save(new WalletBalance('wallet-1', Money::ofMinor(1_000, 'XAF'), Money::zero('XAF'), 0));

    $useCase = new GetWalletBalanceUseCase($wallets, $balances);

    $useCase->get(new GetWalletBalanceCommand('wallet-1', 'someone-elses-project'));
})->throws(WalletNotFound::class);
