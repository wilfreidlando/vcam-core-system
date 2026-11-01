<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

/**
 * ADR-0073 §1 / ADR-0094 §5 — the numbering of the invoices of a
 * project.
 *
 * The sequence is consumed **under a row lock**, so that two
 * simultaneous issuances take two different numbers and no number is
 * ever skipped: a number that was taken and not used is a hole in the
 * legal series of a project.
 *
 * A PostgreSQL sequence would leave one at every rollback — that is why
 * the counter is a table.
 */
interface InvoiceNumberSequenceRepository
{
    /**
     * The next number of the (project, year) pair, and the sequence
     * moves on.
     *
     * The caller must already be inside its transaction: the lock is
     * held until it commits.
     *
     * @param int $year the year of the issuance, in the timezone of the
     *                  project
     */
    public function nextNumber(string $projectId, int $year): int;
}
