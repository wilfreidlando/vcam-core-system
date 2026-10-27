<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0078 §5 — the trace of one reconciliation run: "on doit pouvoir
 * prouver que le contrôle a tourné et qu'il a passé".
 *
 * Immutable, like every other model of this module. `stats` holds
 * counters and identifiers ONLY — never a name, a phone number, an
 * amount tied to a person (ADR-0083 §4): a run says "2 invariants
 * checked, 2 skipped, 1 violation, 1 exception opened", never "Mr X owes
 * 5000".
 */
final readonly class ReconciliationRun
{
    /**
     * @param array<string, mixed> $stats
     */
    public function __construct(
        public string $id,
        public string $projectId,
        /** `null` for level A — an invariant check is not provider-specific (ADR-0078 §5). */
        public ?string $providerCode,
        public ReconciliationRunLevel $level,
        public ?DateTimeImmutable $windowStart,
        public ?DateTimeImmutable $windowEnd,
        public ReconciliationRunStatus $status,
        public array $stats,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}
}
