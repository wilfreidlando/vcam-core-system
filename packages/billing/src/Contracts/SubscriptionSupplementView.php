<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0117 §2 — the read model of a supplement — never
 * `Core\Billing\Domain\Model\SubscriptionSupplement`, which stays
 * internal (G3c).
 *
 * `$status` is a `SubscriptionSupplementStatus` value ('pending',
 * 'active', 'canceled') as a plain string — the enum stays in `Domain/`
 * (G3c).
 *
 * The money is an `int` of minor units plus its currency (G5). The
 * customer is named by the reference the project knows it by, and
 * **never** by a personal value (ADR-0070 §3).
 */
final readonly class SubscriptionSupplementView
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $subscriptionId,
        public string $customerRef,
        public string $meter,
        public string $featureKey,
        public int $quantity,
        public int $unitPriceMinor,
        public int $totalMinor,
        public string $currency,
        public string $status,
        public ?string $invoiceId = null,
        public ?string $invoiceNumber = null,
        public ?string $periodId = null,
        public ?string $activatedAt = null,
        public ?string $canceledAt = null,
        public string $createdAt = '',
        public string $updatedAt = '',
    ) {}
}
