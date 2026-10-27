<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Model\PeriodKind;
use Core\Billing\Domain\Model\PeriodState;
use Core\Billing\Domain\Model\SubscriptionPeriod;
use Core\Billing\Domain\Port\SubscriptionPeriodRepository;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * ADR-0072 §1 — the periods of a contract.
 *
 * The invariants of the table are the ones of ADR-0072 §1: the periods
 * of a contract never overlap (the exclusion constraint), their ranks
 * are unique, and there is at most one `current` period. A violation is
 * translated into a domain exception — the caller never sees a raw
 * driver error.
 */
final class EloquentSubscriptionPeriodRepository implements SubscriptionPeriodRepository
{
    public function findById(string $id): ?SubscriptionPeriod
    {
        $record = SubscriptionPeriodRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findBySeq(string $subscriptionId, int $seq): ?SubscriptionPeriod
    {
        $record = SubscriptionPeriodRecord::query()
            ->where('subscription_id', $subscriptionId)
            ->where('seq', $seq)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findCurrent(string $subscriptionId): ?SubscriptionPeriod
    {
        $record = SubscriptionPeriodRecord::query()
            ->where('subscription_id', $subscriptionId)
            ->where('state', PeriodState::Current->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findUpcoming(string $subscriptionId): ?SubscriptionPeriod
    {
        $record = SubscriptionPeriodRecord::query()
            ->where('subscription_id', $subscriptionId)
            ->where('state', PeriodState::Upcoming->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return list<SubscriptionPeriod>
     */
    public function listBySubscription(string $subscriptionId): array
    {
        // `toBase()` — `orderBy()` is forwarded by Eloquent's builder to
        // the query builder rather than declared on it, which trips a
        // Larastan false positive (the same workaround as
        // EloquentPaymentRepository). `hydrate()` restores the casts.
        $base = SubscriptionPeriodRecord::query()
            ->where('subscription_id', $subscriptionId)
            ->toBase();
        $base->orderBy('seq');

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $periods = [];
        foreach (SubscriptionPeriodRecord::hydrate($rows) as $record) {
            $periods[] = $this->toDomain($record);
        }

        return $periods;
    }

    public function save(SubscriptionPeriod $period): void
    {
        try {
            // SAVEPOINT (nested transaction) — a caught violation must not
            // poison the outer transaction of the use case.
            DB::transaction(function () use ($period): void {
                $attributes = $this->toAttributes($period);

                // `toBase()` — `exists()` is forwarded by Eloquent's
                // builder to the query builder rather than declared on
                // it, which trips a Larastan false positive (the same
                // workaround as EloquentPaymentRepository).
                if (SubscriptionPeriodRecord::query()->whereKey($period->id)->toBase()->exists()) {
                    SubscriptionPeriodRecord::query()->whereKey($period->id)->update($attributes);
                } else {
                    SubscriptionPeriodRecord::query()->create(
                        ['id' => $period->id, 'created_at' => $period->createdAt] + $attributes,
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            // `subscription_periods_seq_unique` (the rank is taken) or
            // `subscription_periods_one_current` (the contract already has
            // a running period).
            throw new InvalidSubscriptionTransition(
                'A contract holds one period per rank and at most one current period (ADR-0072 §1).',
            );
        } catch (QueryException $exception) {
            // The exclusion constraint `subscription_periods_no_overlap`
            // raises an `exclusion_violation` (SQLSTATE 23P01).
            if ($this->isExclusionViolation($exception)) {
                throw new InvalidSubscriptionTransition(
                    'The periods of a contract may never overlap (ADR-0072 §1).',
                );
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(SubscriptionPeriod $period): array
    {
        return [
            'subscription_id' => $period->subscriptionId,
            'project_id' => $period->projectId,
            'seq' => $period->seq,
            'kind' => $period->kind->value,
            'starts_at' => $period->startsAt,
            'ends_at' => $period->endsAt,
            'state' => $period->state->value,
            'invoice_id' => $period->invoiceId,
            'reason' => $period->reason,
            'paid_at' => $period->paidAt,
        ];
    }

    private function toDomain(SubscriptionPeriodRecord $record): SubscriptionPeriod
    {
        return new SubscriptionPeriod(
            id: $record->id,
            subscriptionId: $record->subscription_id,
            projectId: $record->project_id,
            seq: $record->seq,
            kind: PeriodKind::from($record->kind),
            startsAt: DateTimeImmutable::createFromInterface($record->starts_at),
            endsAt: DateTimeImmutable::createFromInterface($record->ends_at),
            state: PeriodState::from($record->state),
            invoiceId: $record->invoice_id,
            reason: $record->reason,
            paidAt: $record->paid_at === null ? null : DateTimeImmutable::createFromInterface($record->paid_at),
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
        );
    }

    /**
     * The SQLSTATE of the driver error: Laravel's `QueryException` keeps
     * the code of the underlying `PDOException`, not its own.
     */
    private function isExclusionViolation(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();
        $code = $previous instanceof PDOException ? $previous->getCode() : $exception->getCode();

        return (string) $code === '23P01';
    }
}
