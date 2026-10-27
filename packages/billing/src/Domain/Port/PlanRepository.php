<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Exception\PlanCodeAlreadyTaken;
use Core\Billing\Domain\Model\Plan;

/**
 * ADR-0071 §1 — the persistence port of the catalogue of a project.
 *
 * A plan is stable: its code never changes, so the subscriptions that
 * reference it stay readable (ADR-0071 §1). The **terms** live in the
 * versions, not here.
 */
interface PlanRepository
{
    /**
     * The plan of a project, by the code the project knows it by
     * (`plans_project_code_unique`).
     */
    public function findByCode(string $projectId, string $code): ?Plan;

    /**
     * The plan of its id, whichever project owns it — the caller carries
     * the tenant check.
     */
    public function findById(string $id): ?Plan;

    /**
     * Persists a plan, created or updated.
     *
     * @throws PlanCodeAlreadyTaken another plan of the same project already carries the code
     */
    public function save(Plan $plan): void;
}
