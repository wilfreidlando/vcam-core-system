<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0071 §2 — the terms of a plan version, changed while it is still a
 * draft.
 *
 * A published version is frozen: the contracts already signed keep the
 * terms they were signed on, so changing a price means publishing a **new**
 * version (ADR-0071 §2). A draft is the operator's working paper — and
 * the catalogue accepts only one draft per offer, so a draft left wrong
 * must be **correctable**, never merely abandoned.
 *
 * `priceMinor` is in minor units (G5) and `currency` a `char(3)`.
 * `intervalUnit` is 'day', 'week', 'month' or 'year' as a plain string.
 *
 * `features` are the named features the project defines (ADR-0071 §3):
 * their keys are format-checked, never listed by the Core.
 */
final readonly class UpdatePlanVersionCommand
{
    /**
     * @param array<string, mixed> $features the named features of the version (ADR-0071 §3)
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $planCode,
        public int $version,
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
