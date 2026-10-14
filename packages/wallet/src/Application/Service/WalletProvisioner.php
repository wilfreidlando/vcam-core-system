<?php

declare(strict_types=1);

namespace Core\Wallet\Application\Service;

use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Parties\Contracts\PartyLookup;
use Core\Support\Identifier;
use Core\Wallet\Domain\Exception\InsufficientFunds;
use Core\Wallet\Domain\Exception\WalletAlreadyExists;
use Core\Wallet\Domain\Exception\WalletProjectMismatch;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use LogicException;

/**
 * Resolves the wallets an operation touches by the product's own party
 * references (ADR-0065), always inside one project:
 *
 * - a **source** must already hold a wallet in the currency — a party that
 *   never received anything has nothing to spend, never a lazily created
 *   empty wallet;
 * - a **destination** is created lazily (party, then wallet and its
 *   balance row), the same way a first payment credit creates it.
 *
 * Must run inside the caller's transaction.
 */
final readonly class WalletProvisioner
{
    public const DEFAULT_PARTY_TYPE = 'wallet_holder';

    public function __construct(
        private WalletRepository $wallets,
        private WalletBalanceRepository $balances,
        private PartyLookup $parties,
        private PartyFinder $partyFinder,
        private Identifier $identifier,
    ) {}

    /**
     * @throws InsufficientFunds the party has no wallet in this currency
     */
    public function source(string $projectId, string $externalRef, string $currency): Wallet
    {
        $party = $this->parties->find($projectId, $externalRef)
            ?? throw InsufficientFunds::forParty($externalRef, $currency);

        $wallet = $this->wallets->findByPartyAndCurrency($party->id, $currency)
            ?? throw InsufficientFunds::forParty($externalRef, $currency);

        if (! $wallet->belongsTo($projectId)) {
            throw WalletProjectMismatch::forWallet($wallet->id, $projectId);
        }

        return $wallet;
    }

    public function destination(string $projectId, string $externalRef, string $currency, ?string $partyType): Wallet
    {
        $party = $this->partyFinder->findOrCreate(new FindOrCreatePartyCommand(
            $projectId,
            $externalRef,
            $partyType ?? self::DEFAULT_PARTY_TYPE,
            [],
        ));

        $existing = $this->wallets->findByPartyAndCurrency($party->id, $currency);
        if ($existing !== null) {
            if (! $existing->belongsTo($projectId)) {
                throw WalletProjectMismatch::forWallet($existing->id, $projectId);
            }

            return $existing;
        }

        $wallet = new Wallet($this->identifier->generate(), $projectId, $party->id, $currency, 'active');

        try {
            $this->wallets->save($wallet);
        } catch (WalletAlreadyExists) {
            return $this->wallets->findByPartyAndCurrency($party->id, $currency)
                ?? throw new LogicException('Wallet creation conflicted but no wallet was found afterwards.');
        }

        $this->balances->initialize($wallet->id, $currency);

        return $wallet;
    }
}
