<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Wallet\Contracts\ListWallets;
use Core\Wallet\Contracts\ListWalletsCommand;
use Core\Wallet\Contracts\WalletPage;
use Core\Wallet\Contracts\WalletSummaryView;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use Psr\Log\LoggerInterface;

final readonly class ListWalletsUseCase implements ListWallets
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletBalanceRepository $balances,
        private LoggerInterface $logger,
    ) {}

    public function list(ListWalletsCommand $command): WalletPage
    {
        $result = $this->wallets->findByProject($command->projectId, $command->page, $command->perPage);

        $walletIds = array_map(static fn (Wallet $wallet): string => $wallet->id, $result['items']);
        $balances = $this->balances->findMany($walletIds);

        $items = array_map(
            fn (Wallet $wallet): WalletSummaryView => $this->toSummary($wallet, $balances[$wallet->id] ?? null),
            $result['items'],
        );

        return new WalletPage($items, $result['total'], $command->page, $command->perPage);
    }

    private function toSummary(Wallet $wallet, ?WalletBalance $balance): WalletSummaryView
    {
        if ($balance === null) {
            // Every wallet is `initialize()`d in the same transaction it
            // is created in (`CreditWalletUseCase::resolveOrCreateWallet`)
            // — a missing balance row is a data-integrity anomaly, not a
            // legitimately empty wallet. Reported at error level rather
            // than silently defaulting to zero, since the operator
            // console is precisely the surface meant to surface this
            // (review finding, Lot 6 follow-up: `GetWalletBalanceUseCase`
            // already throws `WalletNotFound` for the identical
            // condition — this listing path must not mask it instead).
            $this->logger->error('wallet.listing.balance_missing', ['wallet_id' => $wallet->id]);
        }

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
