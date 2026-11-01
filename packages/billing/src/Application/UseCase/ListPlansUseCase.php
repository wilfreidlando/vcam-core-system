<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\PlanDirectory;
use Core\Billing\Application\Port\PlanVersionDirectory;
use Core\Billing\Application\Support\PlanViews;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\PlanPage;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PlanView;
use Core\Billing\Domain\Model\Plan;
use Core\Billing\Domain\Model\PlanStatus;
use Core\Billing\Domain\Model\PlanVersion;
use Core\Support\Access\AccessScope;

/**
 * ADR-0071 §5 — `GET /v1/plans`: the catalogue of one project, page
 * after page.
 *
 * Each plan carries the history of its versions (ADR-0071 §1): the price
 * a contract was signed on is always readable next to the plan it comes
 * from.
 *
 * The scope is a **required** parameter (ADR-0069 §2).
 */
final readonly class ListPlansUseCase implements ListPlans
{
    public function __construct(
        private PlanDirectory $plans,
        private PlanVersionDirectory $versions,
    ) {}

    public function list(ListPlansCommand $command, AccessScope $scope): PlanPage
    {
        if (! $scope->allowsProject($command->projectId)) {
            return new PlanPage([]);
        }

        // An unrecognised status is ignored, never guessed (same
        // convention as the other listings of the Core).
        $status = $command->status !== null && PlanStatus::tryFrom($command->status) !== null
            ? $command->status
            : null;

        $page = $this->plans->listByProject(
            $command->projectId,
            $status,
            $command->cursor,
            $command->limit,
        );

        $items = array_map(
            fn (Plan $plan): PlanView => PlanViews::from(
                $plan,
                array_map(
                    static fn (PlanVersion $version): PlanVersionView => PlanViews::version($version),
                    $this->versions->listByPlan($plan->id),
                ),
            ),
            $page['items'],
        );

        return new PlanPage($items, $page['nextCursor']);
    }
}
