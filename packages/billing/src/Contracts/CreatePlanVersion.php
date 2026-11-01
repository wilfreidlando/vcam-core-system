<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0071 §2 — `POST /v1/plans/{code}/versions`: the terms of a plan.
 *
 * Changing a price means publishing a **new** version: a published one is
 * immutable (ADR-0071 §2), and the subscriptions already signed keep the
 * terms they were signed on.
 */
interface CreatePlanVersion
{
    /**
     * @throws RuntimeException if the version cannot be created — an
     *                          unknown plan, a draft version already open
     *                          for that plan, terms the catalogue does
     *                          not accept. The concrete exception type
     *                          lives in the Domain layer; Contracts must
     *                          not reference it (G3c).
     */
    public function createVersion(CreatePlanVersionCommand $command): PlanVersionView;
}
