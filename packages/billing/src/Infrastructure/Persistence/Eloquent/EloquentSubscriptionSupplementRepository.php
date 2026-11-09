<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Exception\SupplementAlreadyPurchased;
use Core\Billing\Domain\Model\SubscriptionSupplement;
use Core\Billing\Domain\Model\SubscriptionSupplementStatus;
use Core\Billing\Domain\Port\SubscriptionSupplementRepository;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0117 §2 — the supplements of a contract.
 *
 * The repository is the only writer of the table: it hands the domain
 * its rows and takes them back. The clock is the domain's — the
 * timestamps are stamped by the use case, never by the database.
 */
final class EloquentSubscriptionSupplementRepository implements SubscriptionSupplementRepository
{
    public function findById(string $id): ?SubscriptionSupplement
    {
        $record = SubscriptionSupplementRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function listBySubscription(string $subscriptionId): array
    {
        return $this->toDomainAll($this->records(['subscription_id' => $subscriptionId]));
    }

    public function listActiveBySubscription(string $subscriptionId): array
    {
        return $this->toDomainAll($this->records([
            'subscription_id' => $subscriptionId,
            'status' => SubscriptionSupplementStatus::Active->value,
        ]));
    }

    public function listByInvoice(string $invoiceId): array
    {
        return $this->toDomainAll($this->records(['invoice_id' => $invoiceId]));
    }

    /**
     * ADR-0119 §6 — the supplement a gesture already produced.
     *
     * The lookup is scoped by the project: a key is meaningless outside
     * the project that issued it (G13).
     */
    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?SubscriptionSupplement
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $records = $this->records([
            'project_id' => $projectId,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($records === []) {
            return null;
        }

        return $this->toDomain($records[0]);
    }

    public function save(SubscriptionSupplement $supplement): void
    {
        try {
            // SAVEPOINT (nested transaction) — a caught unique violation
            // must not poison the outer transaction of the use case (the
            // same idiom as EloquentPlanRepository::save()).
            DB::transaction(function () use ($supplement): void {
                $attributes = $this->toAttributes($supplement);

                // `toBase()` — `exists()` is forwarded by Eloquent's builder to
                // the query builder rather than declared on it, which trips a
                // Larastan false positive (the same workaround as
                // EloquentSubscriptionPeriodRepository).
                if (SubscriptionSupplementRecord::query()->whereKey($supplement->id)->toBase()->exists()) {
                    SubscriptionSupplementRecord::query()->whereKey($supplement->id)->update($attributes);

                    return;
                }

                SubscriptionSupplementRecord::query()->create(
                    ['id' => $supplement->id, 'created_at' => $supplement->createdAt] + $attributes,
                );
            });
        } catch (UniqueConstraintViolationException) {
            // ADR-0119 §6 — `subscription_supplements_idempotency_unique`:
            // the key of the gesture is unique per project. Another
            // gesture already bought it.
            throw SupplementAlreadyPurchased::because((string) $supplement->idempotencyKey);
        }
    }

    /**
     * @param iterable<SubscriptionSupplementRecord> $records
     * @return list<SubscriptionSupplement>
     */
    private function toDomainAll(iterable $records): array
    {
        $supplements = [];

        foreach ($records as $record) {
            $supplements[] = $this->toDomain($record);
        }

        return $supplements;
    }

    /**
     * The rows of a lookup, hydrated back into the record.
     *
     * `toBase()` — `orderBy()` is forwarded by Eloquent's builder to the
     * query builder rather than declared on it, which trips a Larastan
     * false positive (the same workaround as
     * EloquentSubscriptionPeriodRepository). `hydrate()` restores the
     * casts.
     *
     * @param array<string, mixed> $where
     * @return list<SubscriptionSupplementRecord>
     */
    private function records(array $where): array
    {
        $base = SubscriptionSupplementRecord::query()->toBase();

        foreach ($where as $column => $value) {
            $base->where($column, $value);
        }

        $base->orderBy('created_at');
        $base->orderBy('id');

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = [];

        foreach (SubscriptionSupplementRecord::hydrate($rows) as $record) {
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(SubscriptionSupplement $supplement): array
    {
        return [
            'project_id' => $supplement->projectId,
            'subscription_id' => $supplement->subscriptionId,
            'customer_id' => $supplement->customerId,
            'meter' => $supplement->meter,
            'feature_key' => $supplement->featureKey,
            'quantity' => $supplement->quantity,
            'unit_price_minor' => $supplement->unitPriceMinor,
            'currency' => $supplement->currency,
            'status' => $supplement->status->value,
            'invoice_id' => $supplement->invoiceId,
            'period_id' => $supplement->periodId,
            'idempotency_key' => $supplement->idempotencyKey,
            'activated_at' => $supplement->activatedAt,
            'canceled_at' => $supplement->canceledAt,
            'updated_at' => $supplement->updatedAt,
        ];
    }

    private function toDomain(SubscriptionSupplementRecord $record): SubscriptionSupplement
    {
        return new SubscriptionSupplement(
            id: $record->id,
            projectId: $record->project_id,
            subscriptionId: $record->subscription_id,
            customerId: $record->customer_id,
            meter: $record->meter,
            featureKey: $record->feature_key,
            quantity: $record->quantity,
            unitPriceMinor: $record->unit_price_minor,
            currency: $record->currency,
            status: SubscriptionSupplementStatus::from($record->status),
            invoiceId: $record->invoice_id,
            periodId: $record->period_id,
            activatedAt: $record->activated_at === null ? null : DateTimeImmutable::createFromInterface($record->activated_at),
            canceledAt: $record->canceled_at === null ? null : DateTimeImmutable::createFromInterface($record->canceled_at),
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
            idempotencyKey: $record->idempotency_key,
        );
    }
}
