<?php

declare(strict_types=1);

namespace Core\Wallet\Application\Service;

use Core\Parties\Contracts\PartyLookup;
use Core\Wallet\Contracts\PartyWalletView;
use Core\Wallet\Contracts\WalletOperationView;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Model\WalletOperation;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;

/**
 * Builds the public view of a transfer or withdrawal, with the balances of
 * the wallets it touched — right after posting it, or on a replay.
 */
final readonly class OperationViews
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletBalanceRepository $balances,
        private PartyLookup $parties,
    ) {}

    /**
     * @param array<string, array{wallet: Wallet, ref: string}> $touched keyed by wallet id
     * @param array<string, WalletBalance> $balances keyed by wallet id
     */
    public function of(WalletOperation $operation, array $touched, array $balances): WalletOperationView
    {
        $views = [];
        foreach ($touched as $walletId => $entry) {
            $balance = $balances[$walletId] ?? null;
            if ($balance === null) {
                continue;
            }
            $views[] = new PartyWalletView(
                $walletId,
                $entry['ref'],
                $entry['wallet']->currency,
                $balance->available->amountMinor(),
                $balance->held->amountMinor(),
                $entry['wallet']->status,
            );
        }

        return new WalletOperationView(
            $operation->id,
            $operation->type,
            $operation->idempotencyKey,
            $operation->currency,
            $operation->totalMinor,
            $operation->description,
            $operation->metadata,
            $operation->legs,
            $operation->externalReference,
            $operation->createdAt,
            $views,
        );
    }

    /**
     * A replay answers with the original operation and the balances as they
     * stand now — the same shape as the first answer, nothing moved.
     */
    public function ofReplay(WalletOperation $operation): WalletOperationView
    {
        $touched = [];
        foreach ($operation->legs as $leg) {
            foreach ([$leg['from'], $leg['to']] as $ref) {
                if ($ref === null) {
                    continue;
                }
                $party = $this->parties->find($operation->projectId, $operation->environment, $ref);
                $wallet = $party === null ? null : $this->wallets->findByPartyAndCurrency($party->id, $operation->currency);
                if ($wallet !== null) {
                    $touched[$wallet->id] = ['wallet' => $wallet, 'ref' => $ref];
                }
            }
        }

        return $this->of($operation, $touched, $this->balances->findMany(array_keys($touched)));
    }
}
