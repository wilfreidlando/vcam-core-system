<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\PlanViews;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Domain\Model\PlanStatus;
use Core\Billing\Domain\Model\PlanVersionStatus;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0071 §2 — `POST /v1/plans/{code}/versions/{n}/publish`: the version
 * becomes the one the new contracts take; the contracts already signed
 * keep theirs.
 *
 * Publishing **freezes** the terms: the only transition a published
 * version may undergo afterwards is `published → retired`. The freeze is
 * enforced twice — here, on the state, and by the `plan_version_immutable`
 * trigger, which refuses the write whatever the path.
 *
 * The plan itself becomes **active** when its first version is published:
 * before that it is not proposed to anyone (ADR-0071 §1).
 */
final readonly class PublishPlanVersionUseCase implements PublishPlanVersion
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function publish(PublishPlanVersionCommand $command): PlanVersionView
    {
        return $this->unitOfWork->transactional(function () use ($command): PlanVersionView {
            $plan = $this->plans->findByCode($command->projectId, $command->planCode)
                ?? throw PlanNotFound::becauseCode($command->planCode);

            $version = $this->versions->findByPlanAndVersion($plan->id, $command->version)
                ?? throw PlanVersionNotFound::becauseVersion($command->planCode, $command->version);

            if ($version->status !== PlanVersionStatus::Draft) {
                throw PlanVersionNotEditable::becausePublished($version->version);
            }

            $now = $this->clock->now();

            $version->publish($now);

            $this->versions->save($version);

            if ($plan->status === PlanStatus::Draft) {
                $plan->activate($now);

                $this->plans->save($plan);
            }

            $this->outbox->append(new DomainEvent(
                $this->identifier->generate(),
                $command->projectId,
                'plan.version_published',
                [
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                    'version' => $version->version,
                    'price_minor' => $version->priceMinor,
                    'currency' => $version->currency,
                ],
                $command->correlationId,
                $now,
            ));

            return PlanViews::version($version);
        });
    }
}
