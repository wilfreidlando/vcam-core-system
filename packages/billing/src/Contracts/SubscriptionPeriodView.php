<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * The read model of a period — never
 * `Core\Billing\Domain\Model\SubscriptionPeriod`, which stays internal
 * (G3c).
 *
 * `$kind` is a `PeriodKind` value ('trial', 'paid', 'complimentary') and
 * `$state` a `PeriodState` value ('upcoming', 'current', 'closed'), both
 * as plain strings: the enums stay in `Domain/` (G3c).
 *
 * `reason` is set on a complimentary period, whose motive is mandatory
 * (ADR-0072 §1).
 */
final readonly class SubscriptionPeriodView
{
    public function __construct(
        public string $id,
        public string $subscriptionId,
        public string $projectId,
        public int $seq,
        public string $kind,
        public string $startsAt,
        public string $endsAt,
        public string $state,
        public ?string $invoiceId = null,
        public ?string $reason = null,
        public ?string $paidAt = null,
    ) {}
}
