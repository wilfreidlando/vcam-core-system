<?php

declare(strict_types=1);

namespace Core\Wallet\Application\UseCase;

use Core\Ledger\Contracts\ListAccountEntries;
use Core\Ledger\Contracts\ListAccountEntriesCommand;
use Core\Wallet\Contracts\ListWalletTransactions;
use Core\Wallet\Contracts\ListWalletTransactionsCommand;
use Core\Wallet\Contracts\WalletTransactionPage;
use Core\Wallet\Contracts\WalletTransactionView;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Wallet\Domain\Model\SystemAccount;
use Core\Wallet\Domain\Port\WalletOperationRepository;
use Core\Wallet\Domain\Port\WalletRepository;

/**
 * ADR-0065 — a wallet's movements, newest first, read from the ledger (the
 * source of truth) and enriched with what the caller sent along with each
 * transfer or withdrawal (its reference, description and metadata), joined
 * by the ledger transaction's `external_ref`.
 */
final readonly class ListWalletTransactionsUseCase implements ListWalletTransactions
{
    private const TYPES = [
        'wallet_credit' => 'payment',
        'wallet_debit' => 'debit',
        'wallet_transfer' => 'transfer',
        'wallet_withdrawal' => 'withdrawal',
    ];

    public function __construct(
        private WalletRepository $wallets,
        private WalletOperationRepository $operations,
        private ListAccountEntries $entries,
    ) {}

    public function list(ListWalletTransactionsCommand $command): WalletTransactionPage
    {
        $wallet = $this->wallets->find($command->walletId);
        if ($wallet === null || ! $wallet->belongsTo($command->projectId)) {
            throw WalletNotFound::withId($command->walletId);
        }

        $page = $this->entries->list(new ListAccountEntriesCommand(
            $command->projectId,
            SystemAccount::WALLET,
            $wallet->id,
            $wallet->currency,
            $command->page,
            $command->perPage,
        ));

        $operationIds = [];
        foreach ($page->items as $entry) {
            if ($entry->externalRef !== null && in_array($entry->transactionType, ['wallet_transfer', 'wallet_withdrawal'], true)) {
                $operationIds[] = $entry->externalRef;
            }
        }
        $operations = $operationIds === [] ? [] : $this->operations->findMany(array_values(array_unique($operationIds)));

        $items = [];
        foreach ($page->items as $entry) {
            $type = self::TYPES[$entry->transactionType] ?? $entry->transactionType;
            $operation = $entry->externalRef !== null ? ($operations[$entry->externalRef] ?? null) : null;
            $items[] = new WalletTransactionView(
                $entry->id,
                $entry->createdAt,
                $entry->direction,
                $entry->amountMinor,
                $entry->currency,
                $type,
                $operation?->id,
                $type === 'payment' ? $entry->externalRef : null,
                $operation?->idempotencyKey,
                $operation?->description,
                $operation->metadata ?? [],
            );
        }

        return new WalletTransactionPage($items, $page->total, $page->page, $page->perPage);
    }
}
