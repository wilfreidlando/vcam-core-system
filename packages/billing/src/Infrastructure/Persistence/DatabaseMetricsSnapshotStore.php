<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence;

use Core\Billing\Application\Port\MetricsSnapshotStore;
use Core\Billing\Contracts\MetricsPoint;
use Core\Billing\Contracts\ProjectMetricsView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseMetricsSnapshotStore implements MetricsSnapshotStore
{
    /** The currency of a day on which the project bills nothing. */
    private const NO_CURRENCY = 'XXX';

    public function save(string $day, ProjectMetricsView $metrics): void
    {
        $currencies = array_values(array_unique([
            ...array_keys($metrics->mrrMinor),
            ...array_keys($metrics->collectedMinor),
            ...array_keys($metrics->receivablesMinor),
        ]));

        if ($currencies === []) {
            $currencies = [self::NO_CURRENCY];
        }

        DB::transaction(function () use ($day, $metrics, $currencies): void {
            // The day's rows are replaced as a whole: a currency that left the picture leaves the day too.
            DB::table('billing_daily_metrics')->where('project_id', $metrics->projectId)->where('day', $day)->delete();

            foreach ($currencies as $currency) {
                DB::table('billing_daily_metrics')->insert([
                    'id' => (string) Str::uuid(),
                    'project_id' => $metrics->projectId,
                    'day' => $day,
                    'currency' => $currency,
                    'mrr_minor' => $metrics->mrrMinor[$currency] ?? 0,
                    'collected_minor' => $metrics->collectedMinor[$currency] ?? 0,
                    'receivables_minor' => $metrics->receivablesMinor[$currency] ?? 0,
                    'customers' => $metrics->customers,
                    'active_customers' => $metrics->activeCustomers,
                    'trialing' => $metrics->trialing,
                    'past_due' => $metrics->pastDue,
                    'paused' => $metrics->paused,
                    'new_this_month' => $metrics->newThisMonth,
                    'lost_this_month' => $metrics->lostThisMonth,
                    'churn_bps' => $metrics->churnBps,
                    'computed_at' => $metrics->computedAt,
                ]);
            }
        });
    }

    public function since(string $projectId, string $sinceDay): array
    {
        $points = [];

        foreach (DB::table('billing_daily_metrics')
            ->where('project_id', $projectId)->where('day', '>=', $sinceDay)
            ->orderBy('day')->orderBy('currency')->get() as $row) {
            $points[] = new MetricsPoint(
                day: $this->string($row->day),
                currency: $this->string($row->currency),
                mrrMinor: $this->int($row->mrr_minor),
                collectedMinor: $this->int($row->collected_minor),
                receivablesMinor: $this->int($row->receivables_minor),
                activeCustomers: $this->int($row->active_customers),
                trialing: $this->int($row->trialing),
                pastDue: $this->int($row->past_due),
                newThisMonth: $this->int($row->new_this_month),
                lostThisMonth: $this->int($row->lost_this_month),
            );
        }

        return $points;
    }

    public function projectsToSnapshot(): array
    {
        $ids = [];

        foreach (DB::table('customers')->whereNull('erased_at')->distinct()->pluck('project_id') as $id) {
            $ids[] = $this->string($id);
        }

        return $ids;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
