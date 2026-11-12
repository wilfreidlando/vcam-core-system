<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\PlanViews;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Domain\Exception\InvalidBillingInterval;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\UnsupportedCurrency;
use Core\Billing\Domain\Model\BillingInterval;
use Core\Billing\Domain\Model\BillingIntervalUnit;
use Core\Billing\Domain\Model\PlanFeatures;
use Core\Billing\Domain\Model\PlanVersion;
use Core\Billing\Domain\Model\PlanVersionStatus;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0071 §2 — `POST /v1/plans/{code}/versions`: the terms of a plan.
 *
 * The version is created **draft**: a published one is frozen, and
 * changing a price means publishing a new version, never editing the old
 * one (ADR-0071 §2).
 *
 * Three rules are checked here rather than left to the database:
 *
 * - the **currency** must be one the ledger supports — the list is the
 *   ledger's, never this module's (ADR-0071 §5);
 * - the **features** are validated **by format**, never by list: the Core
 *   knows no feature name (ADR-0071 §3, ADR-0092 §5);
 * - the **interval** must be one of the four units the Core knows, with
 *   a count of at least one (ADR-0071 §1).
 */
final readonly class CreatePlanVersionUseCase implements CreatePlanVersion
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private IsSupportedCurrency $currencies,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function createVersion(CreatePlanVersionCommand $command): PlanVersionView
    {
        return $this->unitOfWork->transactional(function () use ($command): PlanVersionView {
            $plan = $this->plans->findByCode($command->projectId, $command->planCode)
                ?? throw PlanNotFound::becauseCode($command->planCode);

            $currency = strtoupper(trim($command->currency));

            if (! $this->currencies->isSupported($currency)) {
                throw UnsupportedCurrency::becauseNotSupported($currency);
            }

            $unit = BillingIntervalUnit::tryFrom(strtolower(trim($command->intervalUnit)))
                ?? throw new InvalidBillingInterval(
                    "The billing interval unit \"{$command->intervalUnit}\" is unknown: expected \"day\", \"week\", \"month\" or \"year\" (ADR-0071 §1).",
                );

            // The count is checked by the value object itself (ADR-0071 §1).
            $interval = BillingInterval::of($unit, $command->intervalCount);

            $features = PlanFeatures::fromArray($command->features);

            $now = $this->clock->now();

            $version = new PlanVersion(
                id: $this->identifier->generate(),
                planId: $plan->id,
                projectId: $command->projectId,
                version: $this->versions->nextVersionNumber($plan->id),
                priceMinor: $command->priceMinor,
                currency: $currency,
                interval: $interval,
                trialDays: $command->trialDays,
                graceDays: $command->graceDays,
                graceAccess: $command->graceAccess,
                features: $features,
                status: PlanVersionStatus::Draft,
                publishedAt: null,
                createdAt: $now,
            );

            // Throws `PlanVersionConflict` when the offer already holds a
            // draft (it is corrected, not replaced) or when the rank is
            // already taken.
            $this->versions->save($version);

            return PlanViews::version($version);
        });
    }
}
