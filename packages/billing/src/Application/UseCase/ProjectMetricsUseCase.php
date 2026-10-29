<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\MetricsReader;
use Core\Billing\Application\Support\Iso8601;
use Core\Billing\Contracts\ProjectMetrics;
use Core\Billing\Contracts\ProjectMetricsView;
use Core\Ledger\Contracts\TrialBalance;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

/**
 * ADR-0080 §2 — the indicators, computed in one place.
 *
 * - **MRR** : the live recurring contracts (`active`, `past_due`) normalised to
 *   a month — a day ×30/n, a week ×30/7n, a month ÷n, a year ÷12n — rounded
 *   half up, per currency.
 * - **Churn** : contracts lost in the month ÷ contracts alive when it opened.
 * - **Encaissé / créances** : debits of `clearing` and `manual_receipts`
 *   accounts, and the net debit of `customer_receivable` accounts — from the
 *   ledger, which is the only thing that says what really came in.
 */
final readonly class ProjectMetricsUseCase implements ProjectMetrics
{
    public function __construct(
        private MetricsReader $reader,
        private TrialBalance $ledger,
        private Clock $clock,
    ) {}

    public function forProject(string $projectId, AccessScope $scope): ?ProjectMetricsView
    {
        if (! $scope->allowsProject($projectId)) {
            return null;
        }

        $now = $this->clock->now();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $monthStart->modify('+1 month');
        $byStatus = $this->reader->subscriptionsByStatus($projectId);

        $lost = $this->reader->lostBetween($projectId, $monthStart, $nextMonth);
        $base = $this->reader->aliveAt($projectId, $monthStart);
        $pressure = $this->reader->usagePressure($projectId, $now);

        $collected = [];
        $receivables = [];

        // ADR-0080 §2 « Encaissé (période) »: what entered the project **this
        // month** is the debits now minus the debits when the month opened.
        $opening = [];
        foreach ($this->ledger->forProject($projectId, $monthStart) as $account) {
            if (in_array($account->kind, ['clearing', 'manual_receipts'], true)) {
                $opening[$account->currency] = ($opening[$account->currency] ?? 0) + $account->debitMinor;
            }
        }

        foreach ($this->ledger->forProject($projectId) as $account) {
            if (in_array($account->kind, ['clearing', 'manual_receipts'], true)) {
                $collected[$account->currency] = ($collected[$account->currency] ?? 0) + $account->debitMinor;
            }

            if ($account->kind === 'customer_receivable') {
                $receivables[$account->currency] = ($receivables[$account->currency] ?? 0) + $account->netDebitMinor();
            }
        }

        foreach ($collected as $currency => $total) {
            $collected[$currency] = max(0, $total - ($opening[$currency] ?? 0));
        }

        return new ProjectMetricsView(
            projectId: $projectId,
            customers: $this->reader->customers($projectId),
            activeCustomers: $this->reader->activeCustomers($projectId),
            trialing: $byStatus['trialing'] ?? 0,
            pastDue: $byStatus['past_due'] ?? 0,
            paused: $byStatus['paused'] ?? 0,
            newThisMonth: $this->reader->createdBetween($projectId, $monthStart, $nextMonth),
            lostThisMonth: $lost,
            churnBps: $base > 0 ? min(10_000, intdiv($lost * 10_000, $base)) : 0,
            mrrMinor: $this->mrr($projectId),
            collectedMinor: $collected,
            receivablesMinor: array_filter($receivables, static fn (int $amount): bool => $amount !== 0),
            receivableAging: $this->reader->receivableAging($projectId, $now),
            customersOverEightyPercent: $pressure['over80'],
            customersAtLimit: $pressure['atLimit'],
            computedAt: Iso8601::of($now),
        );
    }

    /**
     * @return array<string, int>
     */
    private function mrr(string $projectId): array
    {
        $mrr = [];

        foreach ($this->reader->recurringRevenue($projectId) as $row) {
            // total × 30 / (n × k): one integer division, rounded half up.
            [$numerator, $denominator] = match ($row['unit']) {
                'day' => [$row['total'] * 30, $row['count']],
                'week' => [$row['total'] * 30, 7 * $row['count']],
                'year' => [$row['total'], 12 * $row['count']],
                default => [$row['total'], $row['count']],
            };

            $mrr[$row['currency']] = ($mrr[$row['currency']] ?? 0) + intdiv(2 * $numerator + $denominator, 2 * $denominator);
        }

        return $mrr;
    }
}
