<?php

declare(strict_types=1);

namespace Core\Wallet\Presentation\Http\Presenters;

use Core\Wallet\Contracts\PartyWalletView;
use Core\Wallet\Contracts\WalletOperationView;
use Core\Wallet\Contracts\WalletTransactionView;
use DateTimeInterface;

/**
 * The public JSON shapes of ADR-0065's wallet routes, in one place so the
 * transfer, withdrawal and read routes cannot drift apart.
 */
final class WalletJson
{
    /**
     * @return array<string, mixed>
     */
    public static function operation(WalletOperationView $operation): array
    {
        return [
            'id' => $operation->id,
            'type' => $operation->type,
            'reference' => $operation->reference,
            'currency' => $operation->currency,
            'total_minor' => $operation->totalMinor,
            'description' => $operation->description,
            'metadata' => (object) $operation->metadata,
            'legs' => array_map(static fn (array $leg): array => [
                'from' => $leg['from'],
                'to' => $leg['to'],
                'amount_minor' => $leg['amount_minor'],
            ], $operation->legs),
            'external_reference' => $operation->externalReference,
            'created_at' => $operation->createdAt->format(DateTimeInterface::RFC3339),
            'wallets' => array_map(self::wallet(...), $operation->wallets),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function wallet(PartyWalletView $wallet): array
    {
        return [
            'wallet_id' => $wallet->walletId,
            'party' => $wallet->partyExternalRef,
            'currency' => $wallet->currency,
            'available_minor' => $wallet->availableMinor,
            'held_minor' => $wallet->heldMinor,
            'status' => $wallet->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transaction(WalletTransactionView $transaction): array
    {
        return [
            'id' => $transaction->id,
            'occurred_at' => $transaction->occurredAt->format(DateTimeInterface::RFC3339),
            'direction' => $transaction->direction,
            'amount_minor' => $transaction->amountMinor,
            'currency' => $transaction->currency,
            'type' => $transaction->type,
            'operation_id' => $transaction->operationId,
            'payment_id' => $transaction->paymentId,
            'reference' => $transaction->reference,
            'description' => $transaction->description,
            'metadata' => (object) $transaction->metadata,
        ];
    }
}
