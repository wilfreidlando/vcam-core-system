<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Parties\Contracts\PartyLookup;
use Core\Wallet\Contracts\ListPartyWallets;
use Core\Wallet\Contracts\ListPartyWalletsCommand;
use Core\Wallet\Contracts\PartyWalletView;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;

/**
 * ADR-0065 — a party's wallets and balances, by the product's own
 * reference. A read never creates the party.
 */
final readonly class ListPartyWalletsUseCase implements ListPartyWallets
{
    public function __construct(
        private PartyLookup $parties,
        private WalletRepository $wallets,
        private WalletBalanceRepository $balances,
    ) {}

    public function list(ListPartyWalletsCommand $command): array
    {
        $party = $this->parties->find($command->projectId, $command->environment, $command->partyExternalRef);
        if ($party === null) {
            return [];
        }

        $wallets = array_values(array_filter(
            $this->wallets->findByParty($party->id),
            static fn ($wallet): bool => $wallet->belongsTo($command->projectId, $command->environment),
        ));
        $balances = $this->balances->findMany(array_map(static fn ($wallet): string => $wallet->id, $wallets));

        $views = [];
        foreach ($wallets as $wallet) {
            $balance = $balances[$wallet->id] ?? null;
            $views[] = new PartyWalletView(
                $wallet->id,
                $command->partyExternalRef,
                $wallet->currency,
                $balance?->available->amountMinor() ?? 0,
                $balance?->held->amountMinor() ?? 0,
                $wallet->status,
            );
        }

        return $views;
    }
}
