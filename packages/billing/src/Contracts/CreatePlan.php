<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0071 §5 — `POST /v1/plans`: the plan is created `draft`; it enters
 * the catalogue only once a version is published.
 */
interface CreatePlan
{
    /**
     * @throws RuntimeException if the plan cannot be created — an unknown
     *                          project, a `code` already taken in that
     *                          project. The concrete exception type lives
     *                          in the Domain layer; Contracts must not
     *                          reference it (G3c).
     */
    public function create(CreatePlanCommand $command): PlanView;
}
