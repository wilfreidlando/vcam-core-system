<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0080 §2 — the indicators of one project, defined once.
 *
 * Every monetary figure is **per currency** (never converted, ADR-0080 §6) and
 * in minor units (G5). « Encaissé » and « créances » are read from the
 * **ledger**, not from the invoices: an invoice is not a collection.
 */
final readonly class ProjectMetricsView
{
    /**
     * @param array<string, int> $mrrMinor monthly recurring revenue per currency
     * @param array<string, int> $collectedMinor what entered the project, per currency (ledger)
     * @param array<string, int> $receivablesMinor what is owed to the project, per currency (ledger)
     * @param array<string, array{current: int, d1_30: int, d31_60: int, d60_plus: int}> $receivableAging open invoices by days past due, per currency
     */
    public function __construct(
        public string $projectId,
        public int $customers,
        public int $activeCustomers,
        public int $trialing,
        public int $pastDue,
        public int $paused,
        public int $newThisMonth,
        public int $lostThisMonth,
        public int $churnBps,
        public array $mrrMinor,
        public array $collectedMinor,
        public array $receivablesMinor,
        public array $receivableAging,
        public int $customersOverEightyPercent,
        public int $customersAtLimit,
        public string $computedAt,
    ) {}
}
