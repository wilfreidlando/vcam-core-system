<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Usage;

use Core\Billing\Domain\Model\UsageAdmission;
use Core\Billing\Domain\Model\UsageAdmissionRequest;
use Core\Billing\Domain\Model\UsageCounterSnapshot;
use Core\Billing\Domain\Model\UsageMode;
use Core\Billing\Domain\Port\UsageStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADR-0074 §2 — the atomic admission.
 *
 * Three writes in one transaction: the idempotency key, the conditional
 * UPDATE of the counter, the journal line. The check and the write are
 * **one statement** — the row lock taken by UPDATE serialises concurrent
 * callers, so no interleaving can spend more than the limit; and the row
 * stays locked until the commit, which makes the read that follows it safe.
 *
 * The schema carries no business rule, so the rules live here: the counter
 * never goes below zero, the journal is only ever inserted into, and a
 * refusal rolls back to a savepoint (`DB::transaction()` joins the
 * caller's transaction) so that it leaves no trace at all.
 */
final class PostgresUsageStore implements UsageStore
{
    /** The window of a gauge: it never closes, so it never opens either. */
    private const GAUGE_WINDOW = '1970-01-01 00:00:00.000000+00';

    private const PROJECT_OWNER = '-';

    public function admit(UsageAdmissionRequest $request): UsageAdmission
    {
        try {
            return DB::transaction(fn (): UsageAdmission => $this->perform($request));
        } catch (UsageRefusal $refusal) {
            return $refusal->admission;
        }
    }

    public function counter(
        string $projectId,
        ?string $customerId,
        string $meter,
        ?DateTimeImmutable $windowStart,
    ): ?UsageCounterSnapshot {
        $row = $this->counterQuery($projectId, $customerId, $meter, $windowStart)
            ->first(['used', 'window_start', 'window_end']);

        if ($row === null) {
            return null;
        }

        return new UsageCounterSnapshot(
            $meter,
            $this->int($row->used),
            $windowStart === null ? null : $this->moment($row->window_start),
            $windowStart === null ? null : $this->moment($row->window_end),
        );
    }

    private function perform(UsageAdmissionRequest $r): UsageAdmission
    {
        // a. The same key counts once: a replay answers the decision already taken.
        $fresh = DB::table('usage_idempotency')->insertOrIgnore([
            'project_id' => $r->projectId,
            'meter' => $r->meter,
            'idempotency_key' => $r->idempotencyKey,
            'usage_record_id' => $r->recordId,
            'outcome' => 'admitted',
            'created_at' => $this->ts($r->now),
        ]);

        if ($fresh === 0) {
            // The key is only a replay if it names the SAME call: reusing it
            // for another customer or quantity must not look like success.
            $original = DB::table('usage_records')
                ->whereIn('id', DB::table('usage_idempotency')
                    ->where('project_id', $r->projectId)
                    ->where('meter', $r->meter)
                    ->where('idempotency_key', $r->idempotencyKey)
                    ->select('usage_record_id'))
                ->first(['customer_id', 'quantity']);

            if ($original !== null
                && ($this->nullableString($original->customer_id) !== $r->customerId || $this->int($original->quantity) !== $r->quantity)) {
                return UsageAdmission::refused(UsageAdmission::REFUSED_CONFLICT, 0, $r->limit);
            }

            $snapshot = $this->counter($r->projectId, $r->customerId, $r->meter, $r->windowStart);

            return UsageAdmission::replayed($snapshot->used ?? 0, $r->limit);
        }

        // b. The counter of the current window, opened lazily with the terms
        //    of the plan *as they are now* — they do not move afterwards.
        DB::table('usage_counters')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'project_id' => $r->projectId,
            'owner' => $r->customerId ?? self::PROJECT_OWNER,
            'customer_id' => $r->customerId,
            'meter' => $r->meter,
            'window_start' => $this->windowStart($r->windowStart),
            'window_end' => $this->ts($r->windowEnd),
            'limit_value' => $r->limit,
            'overage' => $r->overage->value,
            'threshold_bps' => $r->thresholdBps,
            'used' => 0,
            'updated_at' => $this->ts($r->now),
        ]);

        $counterId = $this->counterQuery($r->projectId, $r->customerId, $r->meter, $r->windowStart)->value('id');

        if (! is_string($counterId)) {
            // Cannot happen at READ COMMITTED; under a stricter isolation level a
            // missing row must stop here — `where id = NULL` would read as a refusal.
            throw new RuntimeException('The usage counter could not be opened.');
        }

        // b'. A gauge never opens a new window, so its terms would stay those of
        //     the day it was created: refresh them from the plan as it is now (an
        //     upgrade, or billing switched on after usage was recorded) BEFORE the
        //     check reads the limit. It writes only when something changed, so the
        //     hot row is not rewritten on every admission.
        if ($r->windowStart === null) {
            DB::table('usage_counters')
                ->where('id', $counterId)
                ->where(function (Builder $q) use ($r): void {
                    $q->whereRaw('limit_value IS DISTINCT FROM ?', [$r->limit])
                        ->orWhere('overage', '<>', $r->overage->value)
                        ->orWhereRaw('threshold_bps IS DISTINCT FROM ?', [$r->thresholdBps]);
                })
                ->update(['limit_value' => $r->limit, 'overage' => $r->overage->value, 'threshold_bps' => $r->thresholdBps]);
        }

        // c. Check and spend in ONE statement. `record` mode skips the limit.
        //    `$r->quantity` is an int: interpolating it is safe.
        $quantity = $r->quantity;
        $enforce = $r->mode === UsageMode::Enforce;

        $affected = DB::table('usage_counters')
            ->where('id', $counterId)
            ->whereRaw('used + ? >= 0', [$quantity])
            // A release (negative) is never refused for being over a limit.
            ->when($enforce && $quantity > 0, static function (Builder $query) use ($quantity): void {
                $query->where(static function (Builder $within) use ($quantity): void {
                    $within->whereNull('limit_value')
                        ->orWhere('overage', 'allow')
                        ->orWhereRaw('used + ? <= limit_value', [$quantity]);
                });
            })
            ->incrementEach(['used' => $quantity], ['updated_at' => $this->ts($r->now)]);

        // The row is locked by our UPDATE (or read as it stands if refused).
        $counter = DB::table('usage_counters')->where('id', $counterId)->first(['used', 'limit_value', 'threshold_bps']);
        $used = $this->int($counter->used ?? 0);
        $limit = $this->nullableInt($counter->limit_value ?? null);
        $thresholdBps = $this->nullableInt($counter->threshold_bps ?? null);

        if ($affected === 0) {
            throw new UsageRefusal(UsageAdmission::refused(
                $used + $quantity < 0 ? UsageAdmission::REFUSED_UNDERFLOW : UsageAdmission::REFUSED_LIMIT,
                $used,
                $limit,
            ));
        }

        // d. The journal line — the truth the counter projects.
        DB::table('usage_records')->insert([
            'id' => $r->recordId,
            'project_id' => $r->projectId,
            'customer_id' => $r->customerId,
            'meter' => $r->meter,
            'quantity' => $quantity,
            'idempotency_key' => $r->idempotencyKey,
            'source' => $r->source,
            'occurred_at' => $this->ts($r->occurredAt),
            'recorded_at' => $this->ts($r->now),
        ]);

        // e. A crossing is known *here*, from the value before and after:
        //    exactly one concurrent caller sees it (no polling job needed).
        $before = $used - $quantity;
        $thresholdCrossed = false;
        $limitReached = false;

        if ($limit !== null && $quantity > 0) {
            $limitReached = $before < $limit && $used >= $limit;

            if ($thresholdBps !== null && $limit > 0) {
                $mark = $thresholdBps * $limit;
                $thresholdCrossed = $before * 10000 < $mark && $used * 10000 >= $mark;
            }
        }

        return UsageAdmission::admitted($used, $limit, $thresholdCrossed, $limitReached);
    }

    private function counterQuery(string $projectId, ?string $customerId, string $meter, ?DateTimeImmutable $windowStart): Builder
    {
        return DB::table('usage_counters')
            ->where('project_id', $projectId)
            ->where('owner', $customerId ?? self::PROJECT_OWNER)
            ->where('meter', $meter)
            ->where('window_start', $this->windowStart($windowStart));
    }

    private function windowStart(?DateTimeImmutable $moment): string
    {
        return $moment === null ? self::GAUGE_WINDOW : (string) $this->ts($moment);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : $this->int($value);
    }

    private function moment(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value) : null;
    }

    private function ts(?DateTimeImmutable $moment): ?string
    {
        return $moment?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
