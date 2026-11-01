<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0072 §6 — the operator's gesture: a period of N days without
 * invoice.
 */
final readonly class GrantComplimentaryPeriodCommand
{
    /**
     * @param string $reason why the period is granted — mandatory, kept by the audit trail (ADR-0072 §6)
     * @param string $actor "operator:<uuid>", "project:<uuid>" or "service:<name>" — who asked for the write
     */
    public function __construct(
        public string $projectId,
        public string $subscriptionId,
        public int $days,
        public string $reason,
        public string $actor = '',
        public ?string $correlationId = null,
    ) {}
}
