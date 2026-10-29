<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence;

use Core\Billing\Application\Port\MetricsReader;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class DatabaseMetricsReader implements MetricsReader
{
    private const LIVE = ['trialing', 'active', 'past_due'];

    public function subscriptionsByStatus(string $projectId): array
    {
        $out = [];

        foreach (DB::table('subscriptions')->where('project_id', $projectId)->selectRaw('status, COUNT(*) as n')->groupBy('status')->get() as $row) {
            $out[$this->string($row->status)] = $this->int($row->n);
        }

        return $out;
    }

    public function activeCustomers(string $projectId): int
    {
        return $this->int(DB::table('subscriptions')->where('project_id', $projectId)->whereIn('status', self::LIVE)->distinct()->count('customer_id'));
    }

    public function customers(string $projectId): int
    {
        return DB::table('customers')->where('project_id', $projectId)->whereNull('erased_at')->count();
    }

    public function recurringRevenue(string $projectId): array
    {
        $rows = [];

        foreach (DB::table('subscriptions')
            ->where('project_id', $projectId)
            ->whereIn('status', ['active', 'past_due'])
            ->selectRaw('currency, interval_unit, interval_count, SUM(price_minor) as total')
            ->groupBy('currency', 'interval_unit', 'interval_count')
            ->get() as $row) {
            $rows[] = [
                'currency' => $this->string($row->currency),
                'unit' => $this->string($row->interval_unit),
                'count' => max(1, $this->int($row->interval_count)),
                'total' => $this->int($row->total),
            ];
        }

        return $rows;
    }

    public function createdBetween(string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return DB::table('subscriptions')->where('project_id', $projectId)
            ->where('created_at', '>=', $this->ts($from))->where('created_at', '<', $this->ts($to))->count();
    }

    public function lostBetween(string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        // Only contracts that existed when the period opened can be « lost »:
        // a contract signed and expired within the month never counted as a customer.
        return DB::table('subscriptions')->where('project_id', $projectId)
            ->where('created_at', '<', $this->ts($from))
            ->whereIn('status', ['canceled', 'expired'])
            ->whereRaw('COALESCE(ended_at, canceled_at, updated_at) >= ?', [$this->ts($from)])
            ->whereRaw('COALESCE(ended_at, canceled_at, updated_at) < ?', [$this->ts($to)])
            ->count();
    }

    public function aliveAt(string $projectId, DateTimeImmutable $at): int
    {
        return DB::table('subscriptions')->where('project_id', $projectId)
            ->where('created_at', '<', $this->ts($at))
            ->where(function (QueryBuilder $q) use ($at): void {
                $q->whereNotIn('status', ['canceled', 'expired'])
                    ->orWhereRaw('COALESCE(ended_at, canceled_at, updated_at) >= ?', [$this->ts($at)]);
            })->count();
    }

    public function receivableAging(string $projectId, DateTimeImmutable $now): array
    {
        $day = static fn (int $n): string => $now->modify("-{$n} days")->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $out = [];

        $rows = DB::table('invoices')
            ->where('project_id', $projectId)
            ->where('status', 'open')
            ->selectRaw(
                'currency,
                 SUM(CASE WHEN due_at IS NULL OR due_at >= ? THEN total_minor - amount_paid_minor ELSE 0 END) as current_amount,
                 SUM(CASE WHEN due_at < ? AND due_at >= ? THEN total_minor - amount_paid_minor ELSE 0 END) as d1_30,
                 SUM(CASE WHEN due_at < ? AND due_at >= ? THEN total_minor - amount_paid_minor ELSE 0 END) as d31_60,
                 SUM(CASE WHEN due_at < ? THEN total_minor - amount_paid_minor ELSE 0 END) as d60_plus',
                [$day(0), $day(0), $day(30), $day(30), $day(60), $day(60)],
            )
            ->groupBy('currency')
            ->get();

        foreach ($rows as $row) {
            $out[$this->string($row->currency)] = [
                'current' => $this->int($row->current_amount),
                'd1_30' => $this->int($row->d1_30),
                'd31_60' => $this->int($row->d31_60),
                'd60_plus' => $this->int($row->d60_plus),
            ];
        }

        return $out;
    }

    public function usagePressure(string $projectId, DateTimeImmutable $now): array
    {
        // Only the windows that are still open count: a monthly counter that
        // reached its limit in September says nothing about October.
        $base = DB::table('usage_counters')->where('project_id', $projectId)->whereNotNull('customer_id')->whereNotNull('limit_value')->where('limit_value', '>', 0)
            ->where(function (QueryBuilder $q) use ($now): void {
                $q->whereNull('window_end')->orWhere('window_end', '>', $this->ts($now));
            });

        return [
            'over80' => $this->int((clone $base)->whereRaw('CAST(used AS numeric) * 100 >= limit_value * 80')->distinct()->count('customer_id')),
            'atLimit' => $this->int((clone $base)->whereColumn('used', '>=', 'limit_value')->distinct()->count('customer_id')),
        ];
    }

    private function ts(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
