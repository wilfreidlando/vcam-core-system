<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0072 §6 — the operator's gesture: a period of N days without
 * invoice, with a mandatory reason.
 *
 * A complimentary period is an explicit, visible and auditable act —
 * never a fictitious payment (ADR-0072 §Alternatives écartées).
 */
interface GrantComplimentaryPeriod
{
    /**
     * @throws RuntimeException if the period cannot be granted — an
     *                          unknown contract, a contract already
     *                          cancelled or expired. The concrete
     *                          exception type lives in the Domain layer;
     *                          Contracts must not reference it (G3c).
     */
    public function grant(GrantComplimentaryPeriodCommand $command): SubscriptionView;
}
