<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Usage;

use Core\Billing\Application\Port\UsageReader;
use Core\Billing\Contracts\UsageCounterRow;
use Core\Billing\Contracts\UsagePage;
use Core\Billing\Contracts\UsageRecordRow;
use Core\Billing\Infrastructure\Persistence\Cursor;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabaseUsageReader implements UsageReader
{
    public function counters(string $projectId, ?string $filter, ?string $customerRef, ?string $cursor, int $limit): UsagePage
    {
        $query = DB::table('usage_counters as c')
            ->leftJoin('customers as u', function (JoinClause $join): void {
                $join->on('u.id', '=', 'c.customer_id')->on('u.project_id', '=', 'c.project_id');
            })
            ->where('c.project_id', $projectId)
            ->select(['c.id', 'c.meter', 'c.used', 'c.limit_value', 'c.window_end', 'c.updated_at', 'u.external_ref']);

        if ($customerRef !== null && $customerRef !== '') {
            $query->where('u.external_ref', $customerRef);
        }

        if ($filter === 'exceeded') {
            $query->whereNotNull('c.limit_value')->whereColumn('c.used', '>=', 'c.limit_value');
        } elseif ($filter === 'over80') {
            $query->whereNotNull('c.limit_value')->where('c.limit_value', '>', 0)->whereRaw('CAST(c.used AS numeric) * 100 >= c.limit_value * 80');
        }

        $this->after($query, 'c.updated_at', 'c.id', $cursor);

        $rows = $query->orderByDesc('c.updated_at')->orderByDesc('c.id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $items = [];

        foreach ($rows->take($limit) as $row) {
            $used = $this->int($row->used);
            $max = $row->limit_value === null ? null : $this->int($row->limit_value);

            $items[] = new UsageCounterRow(
                is_string($row->external_ref) ? $row->external_ref : '(projet)',
                $this->string($row->meter),
                $used,
                $max,
                is_string($row->window_end) ? $row->window_end : null,
                $max === null || $max <= 0 ? 0 : (int) min(100, round($used * 100 / $max)),
                $this->string($row->updated_at),
            );
        }

        $last = $rows->take($limit)->last();

        return new UsagePage($items, $more && $last !== null ? Cursor::encode($this->string($last->updated_at), $this->string($last->id)) : null);
    }

    public function records(string $projectId, ?string $meter, ?string $customerRef, ?string $cursor, int $limit): UsagePage
    {
        $query = DB::table('usage_records as r')
            ->leftJoin('customers as u', function (JoinClause $join): void {
                $join->on('u.id', '=', 'r.customer_id')->on('u.project_id', '=', 'r.project_id');
            })
            ->where('r.project_id', $projectId)
            ->select(['r.id', 'r.meter', 'r.quantity', 'r.source', 'r.idempotency_key', 'r.occurred_at', 'r.recorded_at', 'u.external_ref']);

        if ($meter !== null && $meter !== '') {
            $query->where('r.meter', $meter);
        }

        if ($customerRef !== null && $customerRef !== '') {
            $query->where('u.external_ref', $customerRef);
        }

        $this->after($query, 'r.recorded_at', 'r.id', $cursor);

        $rows = $query->orderByDesc('r.recorded_at')->orderByDesc('r.id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $items = [];

        foreach ($rows->take($limit) as $row) {
            $items[] = new UsageRecordRow(
                $this->string($row->id),
                is_string($row->external_ref) ? $row->external_ref : null,
                $this->string($row->meter),
                $this->int($row->quantity),
                $this->string($row->source),
                $this->string($row->idempotency_key),
                $this->string($row->occurred_at),
                $this->string($row->recorded_at),
            );
        }

        $last = $rows->take($limit)->last();

        return new UsagePage($items, $more && $last !== null ? Cursor::encode($this->string($last->recorded_at), $this->string($last->id)) : null);
    }

    private function after(\Illuminate\Database\Query\Builder $query, string $momentColumn, string $idColumn, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }

        try {
            [$moment, $id] = Cursor::decode($cursor);
        } catch (InvalidArgumentException) {
            return;
        }

        // A row-value comparison: one index range instead of an OR.
        match ($momentColumn) {
            'c.updated_at' => $query->whereRaw('(c.updated_at, c.id) < (?::timestamptz, ?::uuid)', [$moment, $id]),
            default => $query->whereRaw('(r.recorded_at, r.id) < (?::timestamptz, ?::uuid)', [$moment, $id]),
        };
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
