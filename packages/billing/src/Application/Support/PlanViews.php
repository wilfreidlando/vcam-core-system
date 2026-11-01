<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PlanView;
use Core\Billing\Domain\Model\Plan;
use Core\Billing\Domain\Model\PlanVersion;

/**
 * The mapping from the catalogue of the domain to the read models of
 * `Contracts/` (G3c — the domain models themselves never leave the
 * module).
 */
final readonly class PlanViews
{
    /**
     * @param list<PlanVersionView> $versions the versions of the plan, oldest first
     */
    public static function from(Plan $plan, array $versions): PlanView
    {
        return new PlanView(
            id: $plan->id,
            projectId: $plan->projectId,
            code: $plan->code,
            name: $plan->name,
            description: $plan->description,
            status: $plan->status->value,
            visibility: $plan->visibility->value,
            sortOrder: $plan->sortOrder,
            versions: $versions,
            createdAt: Iso8601::of($plan->createdAt),
            updatedAt: Iso8601::of($plan->updatedAt),
        );
    }

    public static function version(PlanVersion $version): PlanVersionView
    {
        return new PlanVersionView(
            id: $version->id,
            planId: $version->planId,
            projectId: $version->projectId,
            version: $version->version,
            priceMinor: $version->priceMinor,
            currency: $version->currency,
            intervalUnit: $version->interval->unit->value,
            intervalCount: $version->interval->count,
            trialDays: $version->trialDays,
            graceDays: $version->graceDays,
            graceAccess: $version->graceAccess,
            features: $version->features->toArray(),
            status: $version->status->value,
            publishedAt: Iso8601::ofNullable($version->publishedAt),
            createdAt: Iso8601::of($version->createdAt),
        );
    }
}
