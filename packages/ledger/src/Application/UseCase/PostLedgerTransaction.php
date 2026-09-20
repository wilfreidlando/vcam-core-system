<?php

declare(strict_types=1);

namespace Core\Ledger\Application\UseCase;

use Core\Ledger\Contracts\Direction as ContractsDirection;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\LedgerTransactionRef;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Domain\Exception\LedgerTransactionAlreadyExists;
use Core\Ledger\Domain\Model\Direction;
use Core\Ledger\Domain\Model\LedgerEntry;
use Core\Ledger\Domain\Model\LedgerTransaction;
use Core\Ledger\Domain\Port\LedgerTransactionRepository;
use Core\Support\Identifier;
use Core\Support\Money\Money;
use LogicException;

/**
 * The ledger-level idempotence mechanism (ADR-0033 §2): replaying the same
 * `(projectId, idempotencyKey)` is a no-op that returns the original
 * transaction, never a second set of entries.
 */
final readonly class PostLedgerTransaction implements LedgerPoster
{
    public function __construct(
        private LedgerTransactionRepository $transactions,
        private Identifier $identifier,
    ) {}

    public function hasBeenPosted(string $projectId, string $idempotencyKey): bool
    {
        return $this->transactions->findByIdempotencyKey($projectId, $idempotencyKey) !== null;
    }

    public function post(PostLedgerTransactionCommand $command): LedgerTransactionRef
    {
        $existing = $this->transactions->findByIdempotencyKey($command->projectId, $command->idempotencyKey);

        if ($existing !== null) {
            return $this->toRef($existing, wasAlreadyPosted: true);
        }

        $transaction = new LedgerTransaction(
            $this->identifier->generate(),
            $command->projectId,
            $command->type,
            $command->externalRef,
            $command->idempotencyKey,
            array_map($this->toDomainEntry(...), $command->entries),
        );

        try {
            $this->transactions->save($transaction);
        } catch (LedgerTransactionAlreadyExists) {
            // Lost a genuine concurrent race: another call with the same
            // (projectId, idempotencyKey) committed first, between this
            // call's read above and its own insert. The unique
            // constraint already guarantees no double-post happened —
            // this just re-reads the winner instead of letting the
            // violation bubble up as an unhandled 500 (security review
            // finding).
            $winner = $this->transactions->findByIdempotencyKey($command->projectId, $command->idempotencyKey)
                ?? throw new LogicException('Ledger transaction insert conflicted but no transaction was found afterwards.');

            return $this->toRef($winner, wasAlreadyPosted: true);
        }

        return $this->toRef($transaction, wasAlreadyPosted: false);
    }

    private function toDomainEntry(LedgerEntryInput $input): LedgerEntry
    {
        return new LedgerEntry(
            $input->accountId,
            $input->direction === ContractsDirection::Credit ? Direction::Credit : Direction::Debit,
            Money::ofMinor($input->amountMinor, $input->currency),
        );
    }

    private function toRef(LedgerTransaction $transaction, bool $wasAlreadyPosted): LedgerTransactionRef
    {
        return new LedgerTransactionRef($transaction->id, $transaction->type, $transaction->externalRef, $wasAlreadyPosted);
    }
}
