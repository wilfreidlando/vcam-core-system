<?php

declare(strict_types=1);

namespace Core\Billing\Application\Service;

use Core\Billing\Domain\Port\InvoiceNumberSequenceRepository;

/**
 * ADR-0094 §5 — the number of an invoice.
 *
 * `<prefix>-<year>-<sequence>`, the sequence on six digits
 * (`FACT-2026-000042`).
 *
 * The sequence is consumed **under a row lock**, so two simultaneous
 * issuances take two different numbers and no number is skipped: a
 * number taken and not used is a hole in the legal series of a project
 * (ADR-0073 §Alternatives).
 */
final readonly class InvoiceNumberAllocator
{
    public function __construct(
        private InvoiceNumberSequenceRepository $sequences,
    ) {}

    /**
     * The number of the next invoice of a project, for a year.
     *
     * The caller must be inside its transaction: the lock is held until
     * it commits.
     *
     * @param int $year the year of the issuance, in the timezone of the
     *                  project
     */
    public function allocate(string $projectId, int $year, string $prefix): string
    {
        $sequence = $this->sequences->nextNumber($projectId, $year);

        return sprintf('%s-%d-%06d', $prefix, $year, $sequence);
    }
}
