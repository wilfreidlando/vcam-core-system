<?php

declare(strict_types=1);

use Core\Support\Money\Money;
use Core\Wallet\Application\UseCase\ListWalletsUseCase;
use Core\Wallet\Contracts\ListWalletsCommand;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletBalanceRepository;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletRepository;

it('lists only the requesting project\'s wallets, with their balance', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $wallets->save(new Wallet('wallet-1', 'project-a', 'party-1', 'XAF', 'active'));
    $wallets->save(new Wallet('wallet-2', 'project-b', 'party-2', 'XAF', 'active'));
    $balances->save(new WalletBalance('wallet-1', Money::ofMinor(1_000, 'XAF'), Money::ofMinor(200, 'XAF'), 1));

    $useCase = new ListWalletsUseCase($wallets, $balances);
    $page = $useCase->list(new ListWalletsCommand('project-a'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('wallet-1')
        ->and($page->items[0]->availableMinor)->toBe(1_000)
        ->and($page->items[0]->heldMinor)->toBe(200);
});

it('defaults to a zero balance when none has been initialised', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $wallets->save(new Wallet('wallet-1', 'project-a', 'party-1', 'XAF', 'active'));

    $useCase = new ListWalletsUseCase($wallets, $balances);
    $page = $useCase->list(new ListWalletsCommand('project-a'));

    expect($page->items[0]->availableMinor)->toBe(0)
        ->and($page->items[0]->heldMinor)->toBe(0);
});

it('paginates', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    for ($i = 0; $i < 3; $i++) {
        $wallets->save(new Wallet('wallet-'.$i, 'project-a', 'party-'.$i, 'XAF', 'active'));
    }

    $useCase = new ListWalletsUseCase($wallets, $balances);
    $page = $useCase->list(new ListWalletsCommand('project-a', page: 1, perPage: 2));

    expect($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(2);
});
