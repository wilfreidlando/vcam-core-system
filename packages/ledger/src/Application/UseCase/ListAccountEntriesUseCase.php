<?php

declare(strict_types=1);

namespace Core\Ledger\Application\UseCase;

use Core\Ledger\Contracts\LedgerEntryPage;
use Core\Ledger\Contracts\LedgerEntryView;
use Core\Ledger\Contracts\ListAccountEntries;
use Core\Ledger\Contracts\ListAccountEntriesCommand;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\PostedLedgerEntry;
use Core\Ledger\Domain\Port\LedgerAccountRepository;
use Core\Ledger\Domain\Port\LedgerEntryRepository;

final readonly class ListAccountEntriesUseCase implements ListAccountEntries
{
    public function __construct(
        private LedgerAccountRepository $accounts,
        private LedgerEntryRepository $entries,
    ) {}

    public function list(ListAccountEntriesCommand $command): LedgerEntryPage
    {
        $account = $this->accounts->findByComposite(
            $command->projectId,
            AccountKind::fromString($command->kind),
            $command->reference,
            $command->currency,
        );

        // Nothing was ever posted to this account yet — a legitimate
        // empty state, not an error (e.g. a wallet created but never
        // credited).
        if ($account === null) {
            return new LedgerEntryPage([], 0, $command->page, $command->perPage);
        }

        $result = $this->entries->findByAccountPaginated($account->id, $command->page, $command->perPage);

        $items = array_map(
            static fn (PostedLedgerEntry $entry): LedgerEntryView => new LedgerEntryView(
                id: $entry->id,
                transactionId: $entry->transactionId,
                transactionType: $entry->transactionType,
                externalRef: $entry->externalRef,
                direction: $entry->direction->value,
                amountMinor: $entry->amount->amountMinor(),
                currency: $entry->amount->currency(),
                createdAt: $entry->createdAt,
            ),
            $result['items'],
        );

        return new LedgerEntryPage($items, $result['total'], $command->page, $command->perPage);
    }
}
