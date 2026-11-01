<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Model;

use Core\Ledger\Domain\Exception\LedgerAdjustmentReversalRefused;
use Core\Ledger\Domain\Exception\LedgerAdjustmentTransitionRefused;
use DateTimeImmutable;

/**
 * ADR-0076 §6 — an operating correction: the amount, the two accounts it
 * moves the money between, the motive, and the two pairs of eyes that
 * let it reach the ledger.
 *
 * A correction NEVER alters an existing entry (ADR-0076 §5): once
 * approved it produces a new, balanced ledger transaction. That is why
 * this model holds no entry id — only the id of the transaction it
 * produced, and the id of the transaction it reverses.
 *
 * Immutable: every transition returns a new instance, and the rules a
 * correction must follow live here, so the console, the ports and the
 * tests all go through the same checks.
 */
final readonly class LedgerAdjustment
{
    /**
     * The account kinds a correction may NOT touch.
     *
     * `wallet` is the balance of a party, and the `wallet` module is the
     * only one allowed to update it — in the same transaction as the
     * ledger entries that justify it. A correction writes only to the
     * ledger: touching a `wallet` account would leave the ledger's
     * `wallet` account and the real balance of the wallet apart, with
     * nothing to notice the gap (and the invariant of ADR-0078 §2 to
     * fire on the wrong side).
     */
    private const FORBIDDEN_ACCOUNT_KINDS = ['wallet'];

    public function __construct(
        public string $id,
        public string $projectId,
        /** The account debited — where the money comes from. */
        public AccountKind $sourceKind,
        public string $sourceReference,
        /** The account credited — where the money lands. */
        public AccountKind $destinationKind,
        public string $destinationReference,
        /** The amount, in minor units of `currency` (G5). */
        public int $amountMinor,
        public string $currency,
        public string $reason,
        public LedgerAdjustmentStatus $status,
        public string $proposedBy,
        public ?string $approvedBy,
        /** The ledger transaction this correction produced, once approved. */
        public ?string $ledgerTransactionId,
        /**
         * ADR-0076 §5 — the transaction this correction reverses. It is
         * named at the proposal, never deduced: a correction that does
         * not say what it corrects is not a correction.
         */
        public ?string $reversesTransactionId,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $approvedAt,
    ) {}

    /**
     * A new correction, waiting for its second pair of eyes.
     */
    public static function propose(
        string $id,
        string $projectId,
        /**
         * ADR-0076 §5 — the transaction this correction reverses. It is
         * REQUIRED: a correction that does not say what it corrects is
         * not a correction.
         */
        string $reversesTransactionId,
        AccountKind $sourceKind,
        string $sourceReference,
        AccountKind $destinationKind,
        string $destinationReference,
        int $amountMinor,
        string $currency,
        string $reason,
        string $proposedBy,
        DateTimeImmutable $now,
    ): self {
        if ($amountMinor <= 0) {
            throw LedgerAdjustmentTransitionRefused::amountMustBePositive($id);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw LedgerAdjustmentTransitionRefused::reasonRequired($id);
        }

        // A reference is an identifier: the spaces around it are noise,
        // and two references that differ only by their spaces are the
        // same account.
        $sourceReference = trim($sourceReference);
        $destinationReference = trim($destinationReference);

        if (in_array($sourceKind->value, self::FORBIDDEN_ACCOUNT_KINDS, true)
            || in_array($destinationKind->value, self::FORBIDDEN_ACCOUNT_KINDS, true)) {
            throw LedgerAdjustmentTransitionRefused::walletAccountForbidden($id);
        }

        if ($sourceKind->equals($destinationKind) && $sourceReference === $destinationReference) {
            throw LedgerAdjustmentTransitionRefused::sameAccount($id);
        }

        $reversesTransactionId = trim($reversesTransactionId);
        if ($reversesTransactionId === '') {
            throw LedgerAdjustmentReversalRefused::required($id);
        }

        return new self(
            id: $id,
            projectId: $projectId,
            sourceKind: $sourceKind,
            sourceReference: $sourceReference,
            destinationKind: $destinationKind,
            destinationReference: $destinationReference,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            reason: $reason,
            status: LedgerAdjustmentStatus::Proposed,
            proposedBy: $proposedBy,
            approvedBy: null,
            ledgerTransactionId: null,
            reversesTransactionId: $reversesTransactionId,
            createdAt: $now,
            approvedAt: null,
        );
    }

    /**
     * The invariants of a correction, checked where the money is about
     * to MOVE — not where the correction is constructed.
     *
     * A correction read back from the database is rebuilt by the
     * constructor, which validates nothing on purpose: a row written
     * before a rule existed — or written straight into the table, which
     * carries no CHECK constraint on the account kinds — must still be
     * readable (the console lists the corrections waiting for a
     * decision), and must still be REFUSED before it reaches the
     * ledger.
     *
     * So the rules live here, and every path that ends in a ledger
     * entry calls this method: `approve()` and `bookWithoutApproval()`.
     * A refusal is always one of the typed exceptions the console
     * already knows how to show.
     */
    public function assertBookable(): void
    {
        if (in_array($this->sourceKind->value, self::FORBIDDEN_ACCOUNT_KINDS, true)
            || in_array($this->destinationKind->value, self::FORBIDDEN_ACCOUNT_KINDS, true)) {
            throw LedgerAdjustmentTransitionRefused::walletAccountForbidden($this->id);
        }

        // The references are trimmed the way `propose()` trims them:
        // two references that differ only by their spaces are the same
        // account.
        if ($this->sourceKind->equals($this->destinationKind)
            && trim($this->sourceReference) === trim($this->destinationReference)) {
            throw LedgerAdjustmentTransitionRefused::sameAccount($this->id);
        }

        // ADR-0076 §5 — a correction that names no transaction corrects
        // nothing.
        if ($this->reversesTransactionId === null || trim($this->reversesTransactionId) === '') {
            throw LedgerAdjustmentReversalRefused::required($this->id);
        }
    }

    /**
     * The second pair of eyes (ADR-0081 S-15): never the person who
     * proposed the correction.
     */
    public function approve(string $approverId, DateTimeImmutable $now): self
    {
        // The state must be bookable BEFORE it is approved: a
        // correction that breaks an invariant never becomes a ledger
        // transaction, whoever presses the button.
        $this->assertBookable();

        if ($this->status !== LedgerAdjustmentStatus::Proposed) {
            throw LedgerAdjustmentTransitionRefused::notProposed($this->id, $this->status->value);
        }

        if ($approverId === $this->proposedBy) {
            throw LedgerAdjustmentTransitionRefused::sameApprover($this->id);
        }

        return $this->with(
            status: LedgerAdjustmentStatus::Approved,
            approvedBy: $approverId,
            approvedAt: $now,
            ledgerTransactionId: $this->ledgerTransactionId,
        );
    }

    /**
     * The second pair of eyes refuses: nothing is written to the ledger.
     */
    public function reject(string $approverId): self
    {
        if ($this->status !== LedgerAdjustmentStatus::Proposed) {
            throw LedgerAdjustmentTransitionRefused::notProposed($this->id, $this->status->value);
        }

        if ($approverId === $this->proposedBy) {
            throw LedgerAdjustmentTransitionRefused::sameApprover($this->id);
        }

        return $this->with(
            status: LedgerAdjustmentStatus::Rejected,
            approvedBy: null,
            approvedAt: null,
            ledgerTransactionId: $this->ledgerTransactionId,
        );
    }

    /**
     * Below the double-validation threshold: the correction is booked at
     * once, by the person who proposed it.
     *
     * `approvedBy` stays NULL — nobody validated it, and the four-eyes
     * constraint of the table says exactly that (`approved_by IS NULL OR
     * approved_by <> proposed_by`).
     */
    public function bookWithoutApproval(DateTimeImmutable $now): self
    {
        // Same invariant as the approval: the money only moves for a
        // correction whose state allows it.
        $this->assertBookable();

        if ($this->status !== LedgerAdjustmentStatus::Proposed) {
            throw LedgerAdjustmentTransitionRefused::notProposed($this->id, $this->status->value);
        }

        return $this->with(
            status: LedgerAdjustmentStatus::Approved,
            approvedBy: null,
            approvedAt: $now,
            ledgerTransactionId: $this->ledgerTransactionId,
        );
    }

    /**
     * The transaction the correction produced. The correction stays
     * immutable: a correction is a NEW transaction, never a mutation of
     * an existing one — it references the transaction it reverses
     * (`reverses_transaction_id`), it never rewrites it.
     */
    public function withLedgerTransaction(string $transactionId): self
    {
        return $this->with(
            status: $this->status,
            approvedBy: $this->approvedBy,
            approvedAt: $this->approvedAt,
            ledgerTransactionId: $transactionId,
        );
    }

    private function with(
        LedgerAdjustmentStatus $status,
        ?string $approvedBy,
        ?DateTimeImmutable $approvedAt,
        ?string $ledgerTransactionId,
    ): self {
        return new self(
            id: $this->id,
            projectId: $this->projectId,
            sourceKind: $this->sourceKind,
            sourceReference: $this->sourceReference,
            destinationKind: $this->destinationKind,
            destinationReference: $this->destinationReference,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            reason: $this->reason,
            status: $status,
            proposedBy: $this->proposedBy,
            approvedBy: $approvedBy,
            ledgerTransactionId: $ledgerTransactionId,
            reversesTransactionId: $this->reversesTransactionId,
            createdAt: $this->createdAt,
            approvedAt: $approvedAt,
        );
    }
}
