<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0071 §1/§2 — the terms of a plan version.
 *
 * `priceMinor` is in minor units (G5) and `currency` a `char(3)`: one
 * currency per version, no conversion in the Core (ADR-0071 §5).
 * `intervalUnit` is 'day', 'week', 'month' or 'year' as a plain string —
 * the enum stays in `Domain/` (G3c).
 *
 * `features` are the named features the project defines (ADR-0071 §3):
 * their keys are format-checked, never listed by the Core.
 */
final readonly class CreatePlanVersionCommand
{
    /**
     * @param array<string, mixed> $features the named features of the version (ADR-0071 §3)
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $planCode,
        public int $priceMinor,
        public string $currency,
        public string $intervalUnit,
        public int $intervalCount,
        public int $trialDays = 0,
        public int $graceDays = 0,
        public bool $graceAccess = true,
        public array $features = [],
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
