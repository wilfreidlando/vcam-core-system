<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

use Core\Payment\Domain\Model\ReconciliationRun;

/**
 * ADR-0078 §5 — the register of the reconciliation runs. Owned by
 * `payment`, which runs level A; the levels B and C will deposit their
 * own runs here.
 */
interface ReconciliationRunRepository
{
    public function record(ReconciliationRun $run): void;

    /**
     * The most recent runs of a level, newest first.
     *
     * @return list<ReconciliationRun>
     */
    public function findLatest(string $level, int $limit): array;
}
