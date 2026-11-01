<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\PlanViews;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\PlanView;
use Core\Billing\Domain\Exception\InvalidPlan;
use Core\Billing\Domain\Model\Plan;
use Core\Billing\Domain\Model\PlanStatus;
use Core\Billing\Domain\Model\PlanVisibility;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0071 §1/§5 — `POST /v1/plans`: the plan is created **draft**; it
 * enters the catalogue only once a version is published.
 *
 * The `code` is the key the project reads its catalogue by and it stays
 * stable for the whole life of the plan — it is format-checked here so it
 * can never be injected into a URL, a log or a cache key.
 */
final readonly class CreatePlanUseCase implements CreatePlan
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private PlanRepository $plans,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function create(CreatePlanCommand $command): PlanView
    {
        return $this->unitOfWork->transactional(function () use ($command): PlanView {
            self::assertCodeFormat($command->code);

            $visibility = PlanVisibility::tryFrom($command->visibility)
                ?? throw InvalidPlan::becauseBadVisibility($command->visibility);

            $now = $this->clock->now();

            $plan = new Plan(
                id: $this->identifier->generate(),
                projectId: $command->projectId,
                code: $command->code,
                name: $command->name,
                description: $command->description,
                status: PlanStatus::Draft,
                visibility: $visibility,
                sortOrder: $command->sortOrder,
                createdAt: $now,
                updatedAt: $now,
            );

            // Throws `PlanCodeAlreadyTaken` when another plan of the same
            // project already carries the code (ADR-0071 §1).
            $this->plans->save($plan);

            return PlanViews::from($plan, []);
        });
    }

    /**
     * ADR-0071 §1 — the format of the code, checked here as well as by
     * the database: the rule is stated before the round trip.
     */
    private static function assertCodeFormat(string $code): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code) !== 1) {
            throw InvalidPlan::becauseBadCode($code);
        }
    }
}
