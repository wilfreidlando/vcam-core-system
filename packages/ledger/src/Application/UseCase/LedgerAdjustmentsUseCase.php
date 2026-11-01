<?php

declare(strict_types=1);

namespace Core\Ledger\Application\UseCase;

use Core\Ledger\Application\Support\LedgerAdjustmentViews;
use Core\Ledger\Contracts\AccountResolver;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerAdjustments;
use Core\Ledger\Contracts\LedgerAdjustmentView;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\LedgerPoster;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Contracts\ProposeLedgerAdjustmentCommand;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Ledger\Domain\Exception\LedgerAdjustmentNotFound;
use Core\Ledger\Domain\Exception\LedgerAdjustmentReversalRefused;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\LedgerAdjustment;
use Core\Ledger\Domain\Model\LedgerAdjustmentStatus;
use Core\Ledger\Domain\Model\LedgerTransaction;
use Core\Ledger\Domain\Port\LedgerAdjustmentRepository;
use Core\Ledger\Domain\Port\LedgerTransactionRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0076 §6 — the operating corrections of the ledger.
 *
 * A correction is proposed in the console, validated by a second pair of
 * eyes, and then booked as a NORMAL ledger transaction: balanced, in the
 * same database transaction as the state change, and never altering an
 * existing entry (ADR-0076 §5).
 *
 * The approval and the ledger movement share ONE transaction: a
 * correction that is approved but not booked (or booked but not
 * approved) would break the books in one direction or the other.
 *
 * The PROPOSAL is a step of its own (`propose()`), and the movement of
 * money another (`bookWithoutApproval()` / `approve()`): the caller
 * leaves its trace of the proposal between the two, so a correction is
 * never written to the ledger before it is proven (ADR-0076 §6).
 */
final readonly class LedgerAdjustmentsUseCase implements LedgerAdjustments
{
    /**
     * The type of the transaction a correction produces. It is also the
     * type a correction may never counter-pass: a correction never
     * corrects another correction.
     */
    private const ADJUSTMENT_TYPE = 'adjustment';

    public function __construct(
        private UnitOfWork $unitOfWork,
        private LedgerAdjustmentRepository $adjustments,
        private LedgerTransactionRepository $transactions,
        private AccountResolver $accounts,
        private LedgerPoster $poster,
        private Clock $clock,
        private Identifier $identifier,
        /** At or above this amount (minor units), a second person must approve. */
        private int $approvalThresholdMinor,
    ) {}

    public function propose(ProposeLedgerAdjustmentCommand $command, AccessScope $scope): LedgerAdjustmentView
    {
        // ADR-0069 §2 — a project outside the account's scope is "not
        // found": a correction is never proposed on a project the caller
        // cannot see.
        if (! $scope->allowsProject($command->projectId)) {
            throw LedgerAdjustmentNotFound::forProject($command->projectId);
        }

        return $this->unitOfWork->transactional(function () use ($command): LedgerAdjustmentView {
            // ADR-0076 §5 — a correction is a counter-passation: it names
            // the transaction it reverses, and that transaction must be
            // one of THIS project's, and must not be a correction itself.
            $reversed = $this->reversibleTransaction($command->reversesTransactionId, $command->projectId);

            $adjustment = LedgerAdjustment::propose(
                id: $this->identifier->generate(),
                projectId: $command->projectId,
                reversesTransactionId: $reversed->id,
                sourceKind: AccountKind::fromString($command->sourceKind),
                sourceReference: $command->sourceReference,
                destinationKind: AccountKind::fromString($command->destinationKind),
                destinationReference: $command->destinationReference,
                amountMinor: $command->amountMinor,
                currency: $command->currency,
                reason: $command->reason,
                proposedBy: $command->proposedBy,
                now: $this->clock->now(),
            );

            $this->adjustments->create($adjustment);

            // The proposal NEVER moves money: at or above the threshold
            // the correction waits for a second pair of eyes (ADR-0081
            // S-15), and below it `bookWithoutApproval()` books it —
            // after the caller has left its trace of the proposal.
            return LedgerAdjustmentViews::from($adjustment);
        });
    }

    public function bookWithoutApproval(string $adjustmentId, AccessScope $scope): LedgerAdjustmentView
    {
        return $this->unitOfWork->transactional(function () use ($adjustmentId, $scope): LedgerAdjustmentView {
            $adjustment = $this->locked($adjustmentId, $scope);

            // Idempotent, like the approval: a correction already booked
            // (or already refused) is returned as it stands.
            if ($adjustment->status !== LedgerAdjustmentStatus::Proposed) {
                return LedgerAdjustmentViews::from($adjustment);
            }

            // At or above the threshold, a second pair of eyes is needed:
            // nothing is booked, and the correction waits. The default
            // threshold (0) makes that the case for EVERY correction.
            if ($adjustment->amountMinor >= $this->approvalThresholdMinor) {
                return LedgerAdjustmentViews::from($adjustment);
            }

            $booked = $this->book($adjustment->bookWithoutApproval($this->clock->now()));
            $this->adjustments->save($booked);

            return LedgerAdjustmentViews::from($booked);
        });
    }

    public function approve(string $adjustmentId, string $approverOperatorId, AccessScope $scope): LedgerAdjustmentView
    {
        return $this->unitOfWork->transactional(function () use ($adjustmentId, $approverOperatorId, $scope): LedgerAdjustmentView {
            $adjustment = $this->locked($adjustmentId, $scope);

            // Idempotent: approving twice books once. The correction is
            // already in the ledger; a second click must not post a second
            // transaction.
            if ($adjustment->status === LedgerAdjustmentStatus::Approved) {
                return LedgerAdjustmentViews::from($adjustment);
            }

            // Four eyes: the domain refuses when the approver is the
            // person who proposed the correction — and the invariants of
            // the state are checked there too, before any money moves.
            $approved = $adjustment->approve($approverOperatorId, $this->clock->now());

            $booked = $this->book($approved);
            $this->adjustments->save($booked);

            return LedgerAdjustmentViews::from($booked);
        });
    }

    public function reject(string $adjustmentId, string $approverOperatorId, AccessScope $scope): LedgerAdjustmentView
    {
        return $this->unitOfWork->transactional(function () use ($adjustmentId, $approverOperatorId, $scope): LedgerAdjustmentView {
            $adjustment = $this->locked($adjustmentId, $scope);

            // Idempotent, like the approval.
            if ($adjustment->status === LedgerAdjustmentStatus::Rejected) {
                return LedgerAdjustmentViews::from($adjustment);
            }

            $rejected = $adjustment->reject($approverOperatorId);
            $this->adjustments->save($rejected);

            return LedgerAdjustmentViews::from($rejected);
        });
    }

    /**
     * The transaction the correction counter-passes — ADR-0076 §5.
     *
     * Three rules, all of them about the same transaction: it must
     * exist, it must belong to the correction's own project (money never
     * crosses projects, G13), and it must not be a correction itself (a
     * correction never corrects another correction).
     *
     * The rules are checked at the PROPOSAL and again at the BOOKING:
     * they are a property of the state, and the state may have been
     * written outside the proposal path.
     */
    private function reversibleTransaction(string $transactionId, string $projectId): LedgerTransaction
    {
        $transaction = $this->transactions->findById($transactionId);

        if ($transaction === null) {
            throw LedgerAdjustmentReversalRefused::notFound($transactionId);
        }

        if ($transaction->projectId !== $projectId) {
            throw LedgerAdjustmentReversalRefused::anotherProject($transactionId);
        }

        if ($transaction->type === self::ADJUSTMENT_TYPE) {
            throw LedgerAdjustmentReversalRefused::alreadyACorrection($transactionId);
        }

        return $transaction;
    }

    /**
     * Books the correction: a balanced transaction of type `adjustment`,
     * under the idempotence key `adjustment:<id>` — so a replay finds the
     * original transaction instead of posting a second one (ADR-0033 §2).
     *
     * The accounts are resolved, never minted: the correction reuses the
     * chart of accounts (ADR-0076 §2).
     */
    private function book(LedgerAdjustment $adjustment): LedgerAdjustment
    {
        // ADR-0076 §5 — the transaction the correction counter-passes is
        // checked again here: the invariant belongs to the state, and the
        // state may come from a row written outside the proposal path.
        $reversesTransactionId = $adjustment->reversesTransactionId;
        if ($reversesTransactionId === null) {
            throw LedgerAdjustmentReversalRefused::required($adjustment->id);
        }

        $this->reversibleTransaction($reversesTransactionId, $adjustment->projectId);

        // The source account is DEBITED, the destination account is
        // CREDITED: the correction moves the money from one to the other.
        $source = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $adjustment->projectId,
            (string) $adjustment->sourceKind,
            $adjustment->sourceReference,
            $adjustment->currency,
        ));

        $destination = $this->accounts->resolveOrCreate(new ResolveOrCreateAccountCommand(
            $adjustment->projectId,
            (string) $adjustment->destinationKind,
            $adjustment->destinationReference,
            $adjustment->currency,
        ));

        $posted = $this->poster->post(new PostLedgerTransactionCommand(
            projectId: $adjustment->projectId,
            type: self::ADJUSTMENT_TYPE,
            externalRef: $adjustment->id,
            idempotencyKey: 'adjustment:'.$adjustment->id,
            entries: [
                new LedgerEntryInput($source->id, Direction::Debit, $adjustment->amountMinor, $adjustment->currency),
                new LedgerEntryInput($destination->id, Direction::Credit, $adjustment->amountMinor, $adjustment->currency),
            ],
            reversesTransactionId: $adjustment->reversesTransactionId,
        ));

        return $adjustment->withLedgerTransaction($posted->id);
    }

    /**
     * The correction, locked — and only if the caller may act on its
     * project (ADR-0069 §2). The check runs on the locked row, so it
     * cannot be raced by a concurrent read of a stale copy.
     */
    private function locked(string $adjustmentId, AccessScope $scope): LedgerAdjustment
    {
        $adjustment = $this->adjustments->findForUpdate($adjustmentId);

        if ($adjustment === null || ! $scope->allowsProject($adjustment->projectId)) {
            throw LedgerAdjustmentNotFound::withId($adjustmentId);
        }

        return $adjustment;
    }
}
