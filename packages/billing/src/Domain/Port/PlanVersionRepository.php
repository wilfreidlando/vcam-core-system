<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Exception\PlanVersionConflict;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Model\PlanVersion;

/**
 * ADR-0071 §1 — the persistence port of the versions of a plan.
 *
 * A version carries the terms (the price, the currency, the interval, the
 * trial, the grace, the features); it is frozen the moment it is
 * published (ADR-0071 §2).
 */
interface PlanVersionRepository
{
    /**
     * The version of a plan, by its rank (`plan_versions_plan_version_unique`).
     */
    public function findByPlanAndVersion(string $planId, int $version): ?PlanVersion;

    /**
     * The published version of a plan with the highest rank — the one a
     * new contract would be signed on.
     */
    public function findLatestPublished(string $planId): ?PlanVersion;

    /**
     * The version of its id, whichever project owns it — the caller
     * carries the tenant check.
     */
    public function findById(string $id): ?PlanVersion;

    /**
     * The rank the next version of this plan will carry: the highest one
     * already used plus one, and 1 when the plan has no version yet.
     */
    public function nextVersionNumber(string $planId): int;

    /**
     * Persists a version, created or updated.
     *
     * @throws PlanVersionNotEditable the version is published (ADR-0071 §2)
     * @throws PlanVersionConflict the offer already holds a draft, or the rank is already taken
     */
    public function save(PlanVersion $version): void;
}
