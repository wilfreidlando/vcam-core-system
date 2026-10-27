<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\PlanVersionDirectory;
use Core\Billing\Application\Support\PlanViews;
use Core\Billing\Contracts\ArchivePlan;
use Core\Billing\Contracts\ArchivePlanCommand;
use Core\Billing\Contracts\PlanView;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Model\PlanStatus;
use Core\Billing\Domain\Model\PlanVersion;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0071 §5 — `POST /v1/plans/{code}/archive`: the plan takes no new
 * subscription; the ones already running continue to their end.
 *
 * The gesture is idempotent: archiving an archived plan changes nothing
 * and publishes nothing.
 */
final readonly class ArchivePlanUseCase implements ArchivePlan
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private PlanRepository $plans,
        private PlanVersionDirectory $versions,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function archive(ArchivePlanCommand $command): PlanView
    {
        return $this->unitOfWork->transactional(function () use ($command): PlanView {
            $plan = $this->plans->findByCode($command->projectId, $command->planCode)
                ?? throw PlanNotFound::becauseCode($command->planCode);

            $planVersions = array_map(
                static fn (PlanVersion $version) => PlanViews::version($version),
                $this->versions->listByPlan($plan->id),
            );

            if ($plan->status === PlanStatus::Archived) {
                // The gesture is already applied: a replay changes nothing.
                return PlanViews::from($plan, $planVersions);
            }

            $now = $this->clock->now();

            $plan->archive($now);

            $this->plans->save($plan);

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'plan.archived',
                [
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                ],
                $command->correlationId,
                $now,
            ));

            return PlanViews::from($plan, $planVersions);
        });
    }
}
