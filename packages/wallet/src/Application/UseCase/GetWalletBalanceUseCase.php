<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Wallet\Contracts\GetWalletBalance;
use Core\Wallet\Contracts\GetWalletBalanceCommand;
use Core\Wallet\Contracts\WalletBalanceView;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;

final readonly class GetWalletBalanceUseCase implements GetWalletBalance
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletBalanceRepository $balances,
    ) {}

    public function get(GetWalletBalanceCommand $command): WalletBalanceView
    {
        $wallet = $this->wallets->find($command->walletId);

        if ($wallet === null || $wallet->projectId !== $command->requestingProjectId) {
            // Same exception for "does not exist" and "belongs to another
            // project": distinguishing them would let a caller enumerate
            // wallet ids that exist outside its own project.
            throw WalletNotFound::withId($command->walletId);
        }

        $balance = $this->balances->find($wallet->id)
            ?? throw WalletNotFound::withId($command->walletId);

        return new WalletBalanceView(
            $balance->walletId,
            $balance->available->amountMinor(),
            $balance->held->amountMinor(),
            $balance->available->currency(),
        );
    }
}
