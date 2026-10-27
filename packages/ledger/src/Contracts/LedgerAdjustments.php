<?php

declare(strict_types=1);

namespace Core\Ledger\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0076 §6 — the operating corrections (`adjustment`): an amount, two
 * accounts, a motive, and a DOUBLE VALIDATION (a second console account
 * — `org_finance` or the platform — approves).
 *
 * One port for the whole workflow, the way `ResolveMoneyException`
 * carries resolve/approve/reject: a correction is one object with one
 * life, and splitting its steps across several ports would let a caller
 * approve something nobody proposed.
 *
 * ADR-0069 §2 — the scope is a REQUIRED parameter of every method: a
 * correction books money in a project's ledger, so its project must be
 * one the caller may act on. A correction outside the scope is reported
 * as not found (S-04), never settled.
 */
interface LedgerAdjustments
{
    /**
     * The proposal itself: the correction is created, and it waits.
     *
     * NO money moves here — not even below the double-validation
     * threshold. The proposal and the movement of money it may trigger
     * are two separate steps, so the caller can leave its trace of the
     * proposal BEFORE the money moves (ADR-0076 §6: a correction
     * nobody can prove is not a correction).
     */
    public function propose(ProposeLedgerAdjustmentCommand $command, AccessScope $scope): LedgerAdjustmentView;

    /**
     * Below the double-validation threshold, the correction is booked at
     * once, by the person who proposed it — no second pair of eyes.
     *
     * At or above the threshold, NOTHING is booked: the correction
     * waits for `approve()`. The returned view says which happened —
     * its status is `approved` when the money moved, still `proposed`
     * when it did not.
     */
    public function bookWithoutApproval(string $adjustmentId, AccessScope $scope): LedgerAdjustmentView;

    /**
     * The second pair of eyes. Refuses when the approver is the person
     * who proposed it, and posts the correction to the ledger under the
     * idempotence key `adjustment:<id>` — approving twice books once.
     */
    public function approve(string $adjustmentId, string $approverOperatorId, AccessScope $scope): LedgerAdjustmentView;

    /**
     * The second pair of eyes refuses: nothing is written to the ledger.
     */
    public function reject(string $adjustmentId, string $approverOperatorId, AccessScope $scope): LedgerAdjustmentView;
}
