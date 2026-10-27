<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0071 §5 — `POST /v1/plans/{code}/archive`: the plan takes no new
 * subscription; the running ones continue to their end.
 */
interface ArchivePlan
{
    /**
     * @throws RuntimeException if the plan cannot be archived — an unknown
     *                          plan, a plan already archived. The
     *                          concrete exception type lives in the
     *                          Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function archive(ArchivePlanCommand $command): PlanView;
}
