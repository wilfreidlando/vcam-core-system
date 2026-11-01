<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\Iso8601;
use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\UsageDecision;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\FeatureDefinition;
use Core\Billing\Domain\Model\FeatureOverage;
use Core\Billing\Domain\Model\FeatureReset;
use Core\Billing\Domain\Model\FeatureType;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\UsageAdmission;
use Core\Billing\Domain\Model\UsageAdmissionRequest;
use Core\Billing\Domain\Model\UsageMode;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\PlanVersionRepository;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Billing\Domain\Port\UsageStore;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * ADR-0074 §2, §3, §6 — the metered call: resolve the terms, then spend.
 *
 * What the use case decides, in order:
 *
 *  1. no customer named → refused if the project demands one (§6),
 *     otherwise the usage is **recorded against the project**, unlimited;
 *  2. the customer is unknown → `customer_not_found`;
 *  3. the project never signed a contract (billing not activated) → the
 *     usage is recorded, never blocked (§3, existing projects keep working);
 *  4. no contract grants access (ADR-0072 §3) → `entitlement_inactive`;
 *  5. the plan version has no limit feature on this meter →
 *     `feature_not_included`;
 *  6. otherwise the limit, overage rule and window of the plan version are
 *     frozen into the counter and the unit is spent atomically.
 *
 * A refusal is returned as a value (G3c: the caller may be another
 * module) and wrote nothing. Crossings of a threshold or of the limit are
 * published **in the same transaction** that caused them (G8).
 */
final readonly class ConsumeUsageUseCase implements ConsumeUsage
{
    public function __construct(
        private UnitOfWork $unitOfWork,
        private BillingSettingsRepository $settings,
        private CustomerRepository $customers,
        private SubscriptionRepository $subscriptions,
        private SubscriptionPeriodRepository $periods,
        private PlanVersionRepository $versions,
        private UsageStore $store,
        private EventOutbox $outbox,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function consume(ConsumeUsageCommand $command): UsageDecision
    {
        // The schema holds no rule (ADR-0074 §1 is enforced here): a zero
        // quantity would write a journal line that means nothing.
        if ($command->quantity === 0) {
            throw new InvalidArgumentException('A metered quantity cannot be zero.');
        }

        return $this->unitOfWork->transactional(fn (): UsageDecision => $this->resolveAndSpend($command));
    }

    private function resolveAndSpend(ConsumeUsageCommand $command): UsageDecision
    {
        $now = $this->clock->now();
        $mode = UsageMode::tryFrom($command->mode) ?? UsageMode::Enforce;

        if ($command->customerRef === null) {
            if ($this->settings->find($command->projectId)->requireCustomerRef) {
                return $this->refusal(UsageDecision::CUSTOMER_REF_REQUIRED, $command->meter);
            }

            return $this->spendUnlimited($command, null, $now);
        }

        $customer = $this->customers->findByExternalRef($command->projectId, $command->customerRef);

        if ($customer === null || $customer->isErased()) {
            return $this->refusal(UsageDecision::CUSTOMER_NOT_FOUND, $command->meter);
        }

        $live = $this->subscriptions->listLiveByCustomer($customer->id);

        if ($live === [] && ! $this->subscriptions->projectHasSubscriptions($command->projectId)) {
            return $this->spendUnlimited($command, $customer, $now);
        }

        $granted = $this->grantingContracts($customer, $live);

        if ($granted === []) {
            return $this->refusal(UsageDecision::ENTITLEMENT_INACTIVE, $command->meter);
        }

        foreach ($granted as [$subscription, $feature]) {
            if ($feature->meter !== $command->meter || $feature->type !== FeatureType::Limit) {
                continue;
            }

            return $this->spend($command, $customer, $subscription, $feature, $mode, $now);
        }

        return $this->refusal(UsageDecision::FEATURE_NOT_INCLUDED, $command->meter);
    }

    /**
     * Every (contract, limit feature) pair the customer is entitled to now.
     *
     * @param list<Subscription> $live
     * @return list<array{0: Subscription, 1: FeatureDefinition}>
     */
    private function grantingContracts(Customer $customer, array $live): array
    {
        if (! $customer->hasAccess()) {
            return [];
        }

        $pairs = [];

        foreach ($live as $subscription) {
            $version = $this->versions->findById($subscription->planVersionId);

            if ($version === null || ! $subscription->hasAccess($version->graceAccess)) {
                continue;
            }

            foreach ($version->features->all() as $feature) {
                $pairs[] = [$subscription, $feature];
            }
        }

        return $pairs;
    }

    private function spend(
        ConsumeUsageCommand $command,
        Customer $customer,
        Subscription $subscription,
        FeatureDefinition $feature,
        UsageMode $mode,
        DateTimeImmutable $now,
    ): UsageDecision {
        $windowStart = null;
        $windowEnd = null;

        if ($feature->reset === FeatureReset::Period) {
            $period = $subscription->currentPeriodId === null
                ? null
                : $this->periods->findById($subscription->currentPeriodId);

            if ($period === null) {
                return $this->refusal(UsageDecision::ENTITLEMENT_INACTIVE, $command->meter);
            }

            $windowStart = $period->startsAt;
            $windowEnd = $period->endsAt;
        }

        $admission = $this->store->admit(new UsageAdmissionRequest(
            recordId: $this->identifier->generate(),
            projectId: $command->projectId,
            customerId: $customer->id,
            meter: $command->meter,
            quantity: $command->quantity,
            idempotencyKey: $command->idempotencyKey,
            source: $command->source,
            occurredAt: $this->occurredAt($command, $now),
            now: $now,
            mode: $mode,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            limit: $feature->limit,
            overage: $feature->overage ?? FeatureOverage::Block,
            thresholdBps: $feature->thresholdBps,
        ));

        $decision = $this->decision($admission, $command->meter, $windowStart, $windowEnd);

        if ($admission->thresholdCrossed) {
            $this->publish('usage.threshold_reached', $command, $customer, $admission, $feature->thresholdBps, $decision, $now);
        }

        if ($admission->limitReached) {
            $this->publish('usage.limit_reached', $command, $customer, $admission, $feature->thresholdBps, $decision, $now);
        }

        return $decision;
    }

    /**
     * Usage without terms to enforce: kept in the journal, never refused.
     */
    private function spendUnlimited(ConsumeUsageCommand $command, ?Customer $customer, DateTimeImmutable $now): UsageDecision
    {
        $admission = $this->store->admit(new UsageAdmissionRequest(
            recordId: $this->identifier->generate(),
            projectId: $command->projectId,
            customerId: $customer?->id,
            meter: $command->meter,
            quantity: $command->quantity,
            idempotencyKey: $command->idempotencyKey,
            source: $command->source,
            occurredAt: $this->occurredAt($command, $now),
            now: $now,
            mode: UsageMode::Record,
            windowStart: null,
            windowEnd: null,
            limit: null,
            overage: FeatureOverage::Allow,
            thresholdBps: null,
        ));

        return $this->decision($admission, $command->meter, null, null);
    }

    private function decision(
        UsageAdmission $admission,
        string $meter,
        ?DateTimeImmutable $windowStart,
        ?DateTimeImmutable $windowEnd,
    ): UsageDecision {
        $status = match ($admission->outcome) {
            UsageAdmission::ADMITTED => UsageDecision::ADMITTED,
            UsageAdmission::REPLAYED => UsageDecision::REPLAYED,
            UsageAdmission::REFUSED_UNDERFLOW => UsageDecision::UNDERFLOW,
            UsageAdmission::REFUSED_CONFLICT => UsageDecision::KEY_CONFLICT,
            default => UsageDecision::QUOTA_EXCEEDED,
        };

        return new UsageDecision(
            $status,
            $meter,
            $admission->used,
            $admission->limit,
            $windowStart === null ? null : Iso8601::of($windowStart),
            $windowEnd === null ? null : Iso8601::of($windowEnd),
        );
    }

    private function refusal(string $status, string $meter): UsageDecision
    {
        return new UsageDecision($status, $meter, 0, null, null, null);
    }

    private function occurredAt(ConsumeUsageCommand $command, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($command->occurredAt === null) {
            return $now;
        }

        $parsed = date_create_immutable($command->occurredAt);

        return $parsed === false ? $now : $parsed;
    }

    private function publish(
        string $type,
        ConsumeUsageCommand $command,
        Customer $customer,
        UsageAdmission $admission,
        ?int $thresholdBps,
        UsageDecision $decision,
        DateTimeImmutable $now,
    ): void {
        // No personal data (ADR-0070 §3): the customer is its own reference.
        $this->outbox->append(new DomainEvent(
            $this->identifier->generate(),
            $command->projectId,
            $type,
            [
                'customer_ref' => $customer->externalRef,
                'meter' => $command->meter,
                'used' => $admission->used,
                'limit' => $admission->limit,
                'threshold_bps' => $thresholdBps,
                'window_start' => $decision->windowStart,
                'window_end' => $decision->windowEnd,
            ],
            $command->correlationId,
            $now,
        ));
    }
}
