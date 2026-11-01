<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * The read model of the terms of a plan — never
 * `Core\Billing\Domain\Model\PlanVersion`, which stays internal (G3c).
 *
 * `$status` is a `PlanVersionStatus` value ('draft', 'published',
 * 'retired') and `$intervalUnit` 'day', 'week', 'month' or 'year', both
 * as plain strings: the enums stay in `Domain/` (G3c).
 *
 * `priceMinor` is in minor units and `currency` a `char(3)`: never a
 * float (G5), never a `Money` object (ADR-0021).
 */
final readonly class PlanVersionView
{
    /**
     * @param array<string, mixed> $features the named features of the version (ADR-0071 §3)
     */
    public function __construct(
        public string $id,
        public string $planId,
        public string $projectId,
        public int $version,
        public int $priceMinor,
        public string $currency,
        public string $intervalUnit,
        public int $intervalCount,
        public int $trialDays,
        public int $graceDays,
        public bool $graceAccess,
        public array $features,
        public string $status,
        public ?string $publishedAt = null,
        public string $createdAt = '',
    ) {}
}
