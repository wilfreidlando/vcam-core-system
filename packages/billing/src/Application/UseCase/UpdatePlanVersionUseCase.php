<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\PlanViews;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\UpdatePlanVersion;
use Core\Billing\Contracts\UpdatePlanVersionCommand;
use Core\Billing\Domain\Exception\InvalidBillingInterval;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Domain\Exception\UnsupportedCurrency;
use Core\Billing\Domain\Model\BillingInterval;
use Core\Billing\Domain\Model\BillingIntervalUnit;
use Core\Billing\Domain\Model\PlanFeatures;
use Core\Billing\Domain\Port\PlanRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Ledger\Contracts\IsSupportedCurrency;
use Core\Support\UnitOfWork;

/**
 * ADR-0071 §2 — the terms of a **draft** version, corrected.
 *
 * The catalogue holds only one draft per offer: a draft written wrong is
 * not abandoned, it is corrected — otherwise the operator would be stuck
 * with it, unable to publish it and unable to open another one.
 *
 * The same rules as the creation, because they are the same terms:
 *
 * - the **currency** must be one the ledger supports — the list is the
 *   ledger's, never this module's (ADR-0071 §5);
 * - the **features** are validated **by format**, never by list: the Core
 *   knows no feature name (ADR-0071 §3, ADR-0092 §5);
 * - the **interval** must be one of the four units the Core knows, with
 *   a count of at least one (ADR-0071 §1).
 *
 * A **published** version is refused by the domain (ADR-0071 §2), and by
 * the database trigger behind it.
 */
final readonly class UpdatePlanVersionUseCase implements UpdatePlanVersion
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private PlanRepository $plans,
        private PlanVersionRepository $versions,
        private IsSupportedCurrency $currencies,
    ) {}

    public function update(UpdatePlanVersionCommand $command): PlanVersionView
    {
        return $this->unitOfWork->transactional(function () use ($command): PlanVersionView {
            $plan = $this->plans->findByCode($command->projectId, $command->planCode)
                ?? throw PlanNotFound::becauseCode($command->planCode);

            $version = $this->versions->findByPlanAndVersion($plan->id, $command->version)
                ?? throw PlanVersionNotFound::becauseVersion($command->planCode, $command->version);

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

            // The domain refuses a published version (ADR-0071 §2): the
            // terms the subscriptions signed do not move.
            $version->updateTerms(
                $command->priceMinor,
                $currency,
                $interval,
                $command->trialDays,
                $command->graceDays,
                $command->graceAccess,
                $features,
            );

            $this->versions->save($version);

            return PlanViews::version($version);
        });
    }
}
