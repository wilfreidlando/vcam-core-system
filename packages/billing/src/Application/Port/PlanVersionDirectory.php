<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Domain\Model\PlanVersion;
use Core\Billing\Domain\Port\PlanVersionRepository;

/**
 * The **listing** half of the plan version repository: the versions of a
 * plan, oldest first — the history the catalogue shows next to a plan
 * (ADR-0071 §1).
 */
interface PlanVersionDirectory extends PlanVersionRepository
{
    /**
     * Every version of a plan, oldest first.
     *
     * @return list<PlanVersion>
     */
    public function listByPlan(string $planId): array;
}
