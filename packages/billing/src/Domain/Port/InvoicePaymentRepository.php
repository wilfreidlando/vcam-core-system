<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\InvoicePayment;
use DateTimeImmutable;

/**
 * ADR-0073 §2 — the attempts to collect an invoice online.
 *
 * The transitions are **conditional**: they only move a row that is
 * still `pending`, and they say whether they did. A replay of the same
 * status change finds nothing to move and moves nothing (ADR-0073 §2 :
 * « Transition conditionnelle `WHERE status = 'pending'` : un rejeu ne
 * fait rien »).
 */
interface InvoicePaymentRepository
{
    public function save(InvoicePayment $payment): void;

    public function findById(string $id): ?InvoicePayment;

    /**
     * ADR-0073 §2 — the attempt is found by the **payment it belongs
     * to**: that is how `billing` recognises its own attempt when the
     * `payment` module publishes a status change.
     */
    public function findByPaymentId(string $paymentId): ?InvoicePayment;

    /**
     * ADR-0073 §2 — the one attempt still running on an invoice, if any.
     */
    public function findPendingByInvoice(string $invoiceId): ?InvoicePayment;

    /**
     * The successful imputation: the row moves only if it is still
     * pending.
     *
     * @return bool `true` when the row was moved by this call
     */
    public function markSucceeded(
        string $id,
        string $ledgerTransactionId,
        DateTimeImmutable $at,
        ?int $confirmedAmountMinor,
    ): bool;

    /**
     * The attempt did not settle.
     *
     * @return bool `true` when the row was moved by this call
     */
    public function markFailed(string $id, string $reason, DateTimeImmutable $at): bool;

    /**
     * The attempt was abandoned.
     *
     * @return bool `true` when the row was moved by this call
     */
    public function markCanceled(string $id, string $reason, DateTimeImmutable $at): bool;

    /**
     * The attempts of an invoice, the oldest first, page after page.
     *
     * @return array{items: list<InvoicePayment>, nextCursor: ?string}
     */
    public function listByInvoice(string $invoiceId, ?string $cursor, int $limit): array;
}
