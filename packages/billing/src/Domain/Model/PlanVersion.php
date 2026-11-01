<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use DateTimeImmutable;

/**
 * ADR-0071 §1 — the terms of a plan, at a point in time.
 *
 * The price, the currency, the interval, the trial, the grace and the
 * features live here, not on the plan: a plan outlives its prices. A
 * **published** version is frozen — changing a price is publishing a new
 * version, never editing the old one (ADR-0071 §2). The freeze is
 * enforced twice: here, on the state, and by a database trigger.
 */
final class PlanVersion
{
    public function __construct(
        public readonly string $id,
        public readonly string $planId,
        public readonly string $projectId,
        public readonly int $version,
        public int $priceMinor,
        public string $currency,
        public BillingInterval $interval,
        public int $trialDays,
        public int $graceDays,
        public bool $graceAccess,
        public PlanFeatures $features,
        public PlanVersionStatus $status,
        public ?DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    /**
     * The terms may change only while the version is a draft: the
     * published ones are the terms the subscriptions signed.
     */
    public function updateTerms(
        int $priceMinor,
        string $currency,
        BillingInterval $interval,
        int $trialDays,
        int $graceDays,
        bool $graceAccess,
        PlanFeatures $features,
    ): void {
        if ($this->status !== PlanVersionStatus::Draft) {
            throw PlanVersionNotEditable::becausePublished($this->version);
        }

        $this->priceMinor = $priceMinor;
        $this->currency = $currency;
        $this->interval = $interval;
        $this->trialDays = $trialDays;
        $this->graceDays = $graceDays;
        $this->graceAccess = $graceAccess;
        $this->features = $features;
    }

    public function publish(DateTimeImmutable $at): void
    {
        $this->status = PlanVersionStatus::Published;
        $this->publishedAt = $at;
    }

    public function retire(DateTimeImmutable $at): void
    {
        $this->status = PlanVersionStatus::Retired;
    }

    public function isPublished(): bool
    {
        return $this->status === PlanVersionStatus::Published;
    }
}
