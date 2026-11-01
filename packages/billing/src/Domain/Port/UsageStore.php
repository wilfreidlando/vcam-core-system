<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\UsageAdmission;
use Core\Billing\Domain\Model\UsageAdmissionRequest;
use Core\Billing\Domain\Model\UsageCounterSnapshot;
use DateTimeImmutable;

/**
 * ADR-0074 §2 — the atomic counter and its journal.
 *
 * `admit()` is the only way a counter moves. It runs inside the caller's
 * transaction and either writes the idempotency key, the counter and the
 * journal together, or writes nothing at all (a refusal rolls back to a
 * savepoint). Nothing else may touch `used`.
 */
interface UsageStore
{
    public function admit(UsageAdmissionRequest $request): UsageAdmission;

    /**
     * The counter of one meter for the window that opens at `$windowStart`
     * (null for a gauge), or null when nothing was ever counted.
     */
    public function counter(
        string $projectId,
        ?string $customerId,
        string $meter,
        ?DateTimeImmutable $windowStart,
    ): ?UsageCounterSnapshot;
}
