<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use DateTimeImmutable;

/**
 * The aggregates behind the project indicators — a persistence concern.
 */
interface MetricsReader
{
    /**
     * @return array<string, int> subscriptions per status
     */
    public function subscriptionsByStatus(string $projectId): array;

    /**
     * Customers holding at least one contract in `trialing`, `active` or `past_due`.
     */
    public function activeCustomers(string $projectId): int;

    public function customers(string $projectId): int;

    /**
     * What the live recurring contracts bill, grouped so the caller can
     * normalise to a month: one row per (currency, unit, count).
     *
     * @return list<array{currency: string, unit: string, count: int, total: int}>
     */
    public function recurringRevenue(string $projectId): array;

    public function createdBetween(string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): int;

    public function lostBetween(string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): int;

    /**
     * Contracts that were alive when the period opened.
     */
    public function aliveAt(string $projectId, DateTimeImmutable $at): int;

    /**
     * @return array<string, array{current: int, d1_30: int, d31_60: int, d60_plus: int}> remaining on open invoices per currency
     */
    public function receivableAging(string $projectId, DateTimeImmutable $now): array;

    /**
     * @return array{over80: int, atLimit: int} customers at 80 % and more, and at their limit
     */
    public function usagePressure(string $projectId, DateTimeImmutable $now): array;
}
