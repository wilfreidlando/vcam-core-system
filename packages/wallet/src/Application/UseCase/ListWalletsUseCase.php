<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Wallet\Contracts\ListWallets;
use Core\Wallet\Contracts\ListWalletsCommand;
use Core\Wallet\Contracts\WalletPage;
use Core\Wallet\Contracts\WalletSummaryView;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;

final readonly class ListWalletsUseCase implements ListWallets
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletBalanceRepository $balances,
    ) {}

    public function list(ListWalletsCommand $command): WalletPage
    {
        $result = $this->wallets->findByProject($command->projectId, $command->page, $command->perPage);

        $items = array_map(
            fn (Wallet $wallet): WalletSummaryView => $this->toSummary($wallet),
            $result['items'],
        );

        return new WalletPage($items, $result['total'], $command->page, $command->perPage);
    }

    private function toSummary(Wallet $wallet): WalletSummaryView
    {
        $balance = $this->balances->find($wallet->id);

        return new WalletSummaryView(
            id: $wallet->id,
            partyId: $wallet->partyId,
            currency: $wallet->currency,
            status: $wallet->status,
            availableMinor: $balance?->available->amountMinor() ?? 0,
            heldMinor: $balance?->held->amountMinor() ?? 0,
        );
    }
}
