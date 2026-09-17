<?php

declare(strict_types=1);

use Core\Support\Money\Money;
use Core\Wallet\Application\UseCase\ListWalletsUseCase;
use Core\Wallet\Contracts\ListWalletsCommand;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletBalanceRepository;
use Core\Wallet\Tests\Unit\Fakes\InMemoryWalletRepository;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

it('lists only the requesting project\'s wallets, with their balance', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $wallets->save(new Wallet('wallet-1', 'project-a', 'party-1', 'XAF', 'active'));
    $wallets->save(new Wallet('wallet-2', 'project-b', 'party-2', 'XAF', 'active'));
    $balances->save(new WalletBalance('wallet-1', Money::ofMinor(1_000, 'XAF'), Money::ofMinor(200, 'XAF'), 1));

    $useCase = new ListWalletsUseCase($wallets, $balances, new NullLogger);
    $page = $useCase->list(new ListWalletsCommand('project-a'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('wallet-1')
        ->and($page->items[0]->availableMinor)->toBe(1_000)
        ->and($page->items[0]->heldMinor)->toBe(200);
});

it('defaults to a zero balance but logs an error when none has been initialised', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    $wallets->save(new Wallet('wallet-1', 'project-a', 'party-1', 'XAF', 'active'));

    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string|Stringable}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => $message];
        }
    };

    $useCase = new ListWalletsUseCase($wallets, $balances, $logger);
    $page = $useCase->list(new ListWalletsCommand('project-a'));

    expect($page->items[0]->availableMinor)->toBe(0)
        ->and($page->items[0]->heldMinor)->toBe(0)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('error')
        ->and((string) $logger->records[0]['message'])->toBe('wallet.listing.balance_missing');
});

it('paginates', function (): void {
    $wallets = new InMemoryWalletRepository;
    $balances = new InMemoryWalletBalanceRepository;
    for ($i = 0; $i < 3; $i++) {
        $wallets->save(new Wallet('wallet-'.$i, 'project-a', 'party-'.$i, 'XAF', 'active'));
    }

    $useCase = new ListWalletsUseCase($wallets, $balances, new NullLogger);
    $page = $useCase->list(new ListWalletsCommand('project-a', page: 1, perPage: 2));

    expect($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(2);
});
