<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0071 §5 — `GET /v1/plans`: the catalogue of one project.
 *
 * The scope is a **required** parameter (ADR-0069 §2): the listing never
 * crosses the projects the console account may see.
 */
interface ListPlans
{
    public function list(ListPlansCommand $command, AccessScope $scope): PlanPage;
}
