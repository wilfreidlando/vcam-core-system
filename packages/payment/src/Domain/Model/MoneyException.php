<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use Core\Payment\Domain\Exception\MoneyExceptionTransitionRefused;
use DateTimeImmutable;

/**
 * ADR-0077 §3, §4 — money the platform could not allocate, or a financial
 * check that failed, and how a person settled it.
 *
 * Immutable: every transition returns a new instance. The rules a
 * resolution must follow live here, so the console, a future API and
 * the tests all go through the same checks.
 */
final readonly class MoneyException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public MoneyExceptionKind $kind,
        public MoneyExceptionSeverity $severity,
        /** The amount at stake, in minor units of `currency`. */
        public int $amount,
        public string $currency,
        /** What sits on `payment_exceptions` because of this exception (0 = nothing booked). */
        public int $bookedAmount,
        public ?string $paymentId,
        public ?string $subjectRef,
        public string $summary,
        public array $details,
        public MoneyExceptionStatus $status,
        public ?MoneyExceptionResolution $resolution,
        public ?string $resolutionReference,
        public ?string $resolutionNote,
        public ?string $resolvedBy,
        public ?string $approvedBy,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $resolvedAt,
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public static function open(
        string $id,
        string $projectId,
        MoneyExceptionKind $kind,
        MoneyExceptionSeverity $severity,
        int $amount,
        string $currency,
        int $bookedAmount,
        ?string $paymentId,
        ?string $subjectRef,
        string $summary,
        array $details,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: $id, projectId: $projectId, kind: $kind, severity: $severity,
            amount: max(0, $amount), currency: strtoupper($currency), bookedAmount: max(0, min($bookedAmount, max(0, $amount))),
            paymentId: $paymentId, subjectRef: $subjectRef, summary: $summary, details: $details,
            status: MoneyExceptionStatus::Open, resolution: null, resolutionReference: null, resolutionNote: null,
            resolvedBy: null, approvedBy: null, createdAt: $now, resolvedAt: null,
        );
    }

    /**
     * The resolutions a person may choose for this exception. Money on
     * `payment_exceptions` must leave it (refund, revenue, or a wallet);
     * with nothing booked, the only honest resolution is "no movement".
     * `Allocated` is reserved to the module that owns the invoice.
     *
     * Imputing the money to a wallet (ADR-0077 §4) needs a party to
     * impute it TO: the exception's `subjectRef` is the wallet recipient
     * the payment carried. Without one, the resolution is not offered —
     * there would be no wallet to credit.
     *
     * @return list<MoneyExceptionResolution>
     */
    public function allowedResolutions(): array
    {
        if ($this->bookedAmount <= 0) {
            return [MoneyExceptionResolution::NoAction];
        }

        $resolutions = [
            MoneyExceptionResolution::RefundedOutside,
            MoneyExceptionResolution::RecognizedAsRevenue,
        ];

        if ($this->subjectRef !== null && trim($this->subjectRef) !== '') {
            $resolutions[] = MoneyExceptionResolution::AllocatedToWallet;
        }

        return $resolutions;
    }

    /**
     * Proposes a resolution. Below the four-eyes threshold it is final at
     * once; at or above it, it waits for a second person (`approve()`).
     */
    public function propose(
        MoneyExceptionResolution $resolution,
        string $operatorId,
        ?string $reference,
        ?string $note,
        bool $requiresApproval,
        DateTimeImmutable $now,
    ): self {
        if ($this->status !== MoneyExceptionStatus::Open) {
            throw MoneyExceptionTransitionRefused::notOpen($this->id, $this->status->value);
        }

        if (! in_array($resolution, $this->allowedResolutions(), true)) {
            throw MoneyExceptionTransitionRefused::resolutionNotAllowed($this->id, $resolution->value);
        }

        $reference = $reference === null ? null : trim($reference);
        if ($resolution === MoneyExceptionResolution::RefundedOutside && ($reference === null || $reference === '')) {
            throw MoneyExceptionTransitionRefused::referenceRequired($this->id);
        }

        $note = $note === null ? null : trim($note);
        if ($note === null || $note === '') {
            throw MoneyExceptionTransitionRefused::noteRequired($this->id);
        }

        return $this->with(
            status: $requiresApproval ? MoneyExceptionStatus::AwaitingApproval : MoneyExceptionStatus::Resolved,
            resolution: $resolution,
            reference: $reference === '' ? null : $reference,
            note: $note,
            resolvedBy: $operatorId,
            approvedBy: null,
            resolvedAt: $requiresApproval ? null : $now,
        );
    }

    /** Second person's approval of a proposed resolution (ADR-0081 S-15). */
    public function approve(string $approverId, DateTimeImmutable $now): self
    {
        if ($this->status !== MoneyExceptionStatus::AwaitingApproval) {
            throw MoneyExceptionTransitionRefused::notAwaitingApproval($this->id, $this->status->value);
        }

        if ($approverId === $this->resolvedBy) {
            throw MoneyExceptionTransitionRefused::sameApprover($this->id);
        }

        return $this->with(
            status: MoneyExceptionStatus::Resolved,
            resolution: $this->resolution,
            reference: $this->resolutionReference,
            note: $this->resolutionNote,
            resolvedBy: $this->resolvedBy,
            approvedBy: $approverId,
            resolvedAt: $now,
        );
    }

    /** Sends a proposed resolution back to `open` (the approver refuses it). */
    public function reject(string $approverId): self
    {
        if ($this->status !== MoneyExceptionStatus::AwaitingApproval) {
            throw MoneyExceptionTransitionRefused::notAwaitingApproval($this->id, $this->status->value);
        }

        if ($approverId === $this->resolvedBy) {
            throw MoneyExceptionTransitionRefused::sameApprover($this->id);
        }

        return $this->with(
            status: MoneyExceptionStatus::Open,
            resolution: null,
            reference: null,
            note: null,
            resolvedBy: null,
            approvedBy: null,
            resolvedAt: null,
        );
    }

    /** Whether reaching `resolved` must move money out of `payment_exceptions`. */
    public function movesMoney(): bool
    {
        return $this->status === MoneyExceptionStatus::Resolved
            && $this->bookedAmount > 0
            && $this->resolution?->creditAccount() !== null;
    }

    private function with(
        MoneyExceptionStatus $status,
        ?MoneyExceptionResolution $resolution,
        ?string $reference,
        ?string $note,
        ?string $resolvedBy,
        ?string $approvedBy,
        ?DateTimeImmutable $resolvedAt,
    ): self {
        return new self(
            id: $this->id, projectId: $this->projectId, kind: $this->kind, severity: $this->severity,
            amount: $this->amount, currency: $this->currency, bookedAmount: $this->bookedAmount,
            paymentId: $this->paymentId, subjectRef: $this->subjectRef, summary: $this->summary, details: $this->details,
            status: $status, resolution: $resolution, resolutionReference: $reference, resolutionNote: $note,
            resolvedBy: $resolvedBy, approvedBy: $approvedBy, createdAt: $this->createdAt, resolvedAt: $resolvedAt,
        );
    }
}
