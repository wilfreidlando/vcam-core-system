<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Port;

use Core\Notification\Domain\Model\QuotaVerdict;

/**
 * ADR-0074 §5 — the notification asks the quota authority before it
 * exists, and gives the unit back if it never reached anyone.
 *
 * `consume()` joins the caller's transaction: a refusal is then rolled
 * back together with the `queued` notification (no row, no provider call).
 */
interface QuotaGuard
{
    public function consume(string $projectId, ?string $customerRef, string $capability, string $idempotencyKey): QuotaVerdict;

    /**
     * Gives the unit back when every provider failed. A no-op when the
     * project does not refund failed usage or never counted this one.
     */
    public function release(string $projectId, ?string $customerRef, string $capability, string $idempotencyKey): void;
}
