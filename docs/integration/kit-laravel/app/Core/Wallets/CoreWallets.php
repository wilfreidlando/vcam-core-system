<?php

declare(strict_types=1);

namespace App\Core\Wallets;

use App\Core\CoreClient;

/**
 * Balances and movements held by the Core, per « party » — a party is whoever
 * YOU name (a user, a partner, an `escrow` account…) by your own reference.
 *
 * - Money enters a wallet **only** through a confirmed payment that names it
 *   (`wallet_recipient_external_ref`): there is no free credit.
 * - `transfer()` moves value between wallets of your project, atomically.
 * - `withdraw()` RECORDS a payout you made yourself (mobile money, bank): it
 *   debits the wallet and keeps your proof; the Core does not send the money.
 *
 * Amounts are integers in the currency's smallest unit.
 */
final class CoreWallets
{
    public function __construct(private readonly CoreClient $core) {}

    /**
     * @return list<array{wallet_id: string, currency: string, available_minor: int, held_minor: int}>
     */
    public function ofParty(string $partyRef): array
    {
        /** @var list<array{wallet_id: string, currency: string, available_minor: int, held_minor: int}> */
        return $this->core->get('parties/'.rawurlencode($partyRef).'/wallets')['data'] ?? [];
    }

    /** The spendable balance of a party in a currency (0 if it has no wallet yet). */
    public function available(string $partyRef, string $currency): int
    {
        foreach ($this->ofParty($partyRef) as $wallet) {
            if ($wallet['currency'] === $currency) {
                return (int) $wallet['available_minor'];
            }
        }

        return 0;
    }

    /**
     * @return array{wallet_id: string, currency: string, available_minor: int, held_minor: int}
     */
    public function balance(string $walletId): array
    {
        /** @var array{wallet_id: string, currency: string, available_minor: int, held_minor: int} */
        return $this->core->get("wallets/{$walletId}/balance");
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function transactions(string $walletId, int $page = 1, int $perPage = 50): array
    {
        /** @var array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
        return $this->core->get("wallets/{$walletId}/transactions", ['page' => $page, 'per_page' => min(100, max(1, $perPage))]);
    }

    /**
     * Move value between parties, all legs or none (1 to 20 legs, one currency).
     * Throws CoreException `insufficient-funds` (409) when a source is short.
     *
     * @param list<array{from: string, to: string, amount: int}> $legs
     * @param array<string, mixed> $metadata opaque, 4 KB at most
     * @param string $key YOUR id of this transfer: replayed, it moves value once
     * @return array<string, mixed>
     */
    public function transfer(string $key, string $currency, array $legs, ?string $description = null, array $metadata = []): array
    {
        return $this->core->post('wallets/transfers', array_filter([
            'currency' => $currency,
            'legs' => $legs,
            'description' => $description === null ? null : mb_substr($description, 0, 255),
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn (mixed $v): bool => $v !== null), $key);
    }

    /**
     * Record that you paid `amount` out of a party's wallet, outside the Core.
     * `externalReference` is your proof (the mobile-money transaction id).
     *
     * @return array<string, mixed>
     */
    public function withdraw(string $key, string $partyRef, int $amount, string $currency, ?string $description = null, ?string $externalReference = null): array
    {
        return $this->core->post('wallets/withdrawals', array_filter([
            'party' => $partyRef,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description === null ? null : mb_substr($description, 0, 255),
            'external_reference' => $externalReference === null ? null : mb_substr($externalReference, 0, 120),
        ], static fn (mixed $v): bool => $v !== null), $key);
    }
}
