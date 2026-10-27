<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Application\Port\SubscriptionDirectory;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Model\BillingInterval;
use Core\Billing\Domain\Model\BillingIntervalUnit;
use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Model\SubscriptionStatus;
use Core\Billing\Infrastructure\Persistence\Cursor;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0072 §1 — the contracts of a project.
 *
 * **The optimistic lock (ADR-0072 §2).** Every transition of a contract
 * is an `UPDATE subscriptions SET … WHERE id = ? AND status = :expectedStatus
 * AND version = :expectedVersion`. The expected status and version are
 * the ones the row carried **before** the transition, and the write is
 * refused — never applied blindly — as soon as the row has moved.
 *
 * Two comparisons guard it:
 *
 * - the **persisted version** against the **in-memory one**: the contract
 *   must have moved forward since the row was read. Equal versions mean
 *   the row already carries that version — the transition was already
 *   applied (a replay) or nothing was transitioned at all; a greater one
 *   means the row moved beyond us. Both are refused;
 * - the **expected status and version** of the write against the
 *   persisted row: they are the ones read by this port, and the `UPDATE`
 *   itself carries them, so a write that lost the race updates zero
 *   rows and is refused as well.
 *
 * The ADR lets a zero-row update be either a conflict or an idempotent
 * replay; this adapter always raises the conflict, and the use case
 * decides which of the two it is.
 */
final class EloquentSubscriptionRepository implements SubscriptionDirectory
{
    /**
     * ADR-0088 §2 — a listing never returns more than a hundred rows.
     */
    private const MAX_LIMIT = 100;

    /**
     * The statuses a contract is live in — the ones the partial unique
     * index `subscriptions_live_unique` covers (ADR-0072 §1).
     */
    private const LIVE_STATUSES = ['incomplete', 'trialing', 'active', 'past_due', 'paused'];

    /**
     * The status and the version the row carried the last time this port
     * read it — the expected values of the next write (ADR-0072 §2).
     *
     * @var array<string, array{status: string, version: int}>
     */
    private array $expected = [];

    public function findById(string $id): ?Subscription
    {
        $record = SubscriptionRecord::query()->find($id);

        if ($record === null) {
            return null;
        }

        $this->expected[$id] = ['status' => $record->status, 'version' => $record->version];

        return $this->toDomain($record);
    }

    public function findLiveByCustomerAndPlan(string $customerId, string $planId): ?Subscription
    {
        // `toBase()` — `whereIn()` is forwarded by Eloquent's builder to
        // the query builder rather than declared on it, which trips a
        // Larastan false positive (the same workaround as
        // EloquentPaymentRepository). `hydrate()` restores the casts.
        $base = SubscriptionRecord::query()
            ->where('customer_id', $customerId)
            ->where('plan_id', $planId)
            ->toBase();
        $base->whereIn('status', self::LIVE_STATUSES);

        $row = $base->first();

        if ($row === null) {
            return null;
        }

        $record = SubscriptionRecord::hydrate([(array) $row])->sole();

        $this->expected[$record->id] = ['status' => $record->status, 'version' => $record->version];

        return $this->toDomain($record);
    }

    public function save(Subscription $subscription): void
    {
        $id = $subscription->id;
        $persisted = SubscriptionRecord::query()->find($id);

        if ($persisted === null) {
            $this->insert($subscription);

            return;
        }

        if ($persisted->version >= $subscription->version) {
            throw new InvalidSubscriptionTransition(sprintf(
                'The subscription "%s" has moved on: version %d was expected but the stored version is %d (ADR-0072 §2).',
                $id,
                $subscription->version - 1,
                $persisted->version,
            ));
        }

        // The expected status and version of the write: the ones the row
        // carried before the transition.
        $expected = $this->expected[$id] ?? [
            'status' => $persisted->status,
            'version' => $persisted->version,
        ];

        if ($expected['version'] !== $persisted->version) {
            throw new InvalidSubscriptionTransition(sprintf(
                'The subscription "%s" has moved on since it was read: version %d was expected but the stored version is %d (ADR-0072 §2).',
                $id,
                $expected['version'],
                $persisted->version,
            ));
        }

        try {
            $affected = SubscriptionRecord::query()
                ->where('id', $id)
                ->where('status', $expected['status'])
                ->where('version', $expected['version'])
                ->update($this->toAttributes($subscription));
        } catch (UniqueConstraintViolationException) {
            throw $this->liveContractTaken();
        }

        if ($affected === 0) {
            // The row moved between the read and the write: the contract
            // is not ours to write any more.
            throw new InvalidSubscriptionTransition(sprintf(
                'The subscription "%s" was modified concurrently: the transition was refused (ADR-0072 §2).',
                $id,
            ));
        }

        $this->expected[$id] = [
            'status' => $subscription->status->value,
            'version' => $subscription->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * ADR-0088 §2 — the contracts of a project, page after page.
     *
     * @return array{items: list<Subscription>, nextCursor: ?string}
     */
    public function listByProject(
        string $projectId,
        ?string $customerId,
        ?string $status,
        ?string $cursor,
        int $limit,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // See EloquentCustomerRepository::listByProject() for why the whole
        // query is built on `toBase()`.
        $base = SubscriptionRecord::query()->toBase()->where('project_id', $projectId);

        if ($customerId !== null && $customerId !== '') {
            $base->where('customer_id', $customerId);
        }

        if ($status !== null && $status !== '') {
            $base->where('status', $status);
        }

        if ($cursor !== null) {
            [$createdAt, $id] = Cursor::decode($cursor);

            $base->where(static function (QueryBuilder $q) use ($createdAt, $id): void {
                $q->where('created_at', '>', $createdAt)
                    ->orWhere(static function (QueryBuilder $q2) use ($createdAt, $id): void {
                        $q2->where('created_at', '=', $createdAt)->where('id', '>', $id);
                    });
            });
        }

        $rows = $base->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get()
            ->map(static fn (object $row): array => (array) $row)->all();

        $records = SubscriptionRecord::hydrate($rows);

        $hasMore = $records->count() > $limit;
        $records = $records->take($limit);

        $items = [];
        foreach ($records as $record) {
            $items[] = $this->toDomain($record);
        }

        $last = $records->last();

        return [
            'items' => $items,
            'nextCursor' => $hasMore && $last !== null
                ? Cursor::encode($last->created_at->format('Y-m-d H:i:s.uP'), $last->id)
                : null,
        ];
    }

    /**
     * ADR-0072 §8 — the contracts whose next step may be due: the
     * scheduler's candidates.
     *
     * The set is deliberately broad: every contract that is not in a
     * final state **and** whose current period ends within the maximum
     * renewal lead (90 days — the ceiling `billing_settings` allows),
     * plus every `incomplete` contract, whose expiry does not wait for
     * the end of its period. The precise due-ness is decided by the use
     * case, per project.
     *
     * @return list<Subscription>
     */
    public function findDueForAdvance(DateTimeImmutable $now, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $horizon = $now->modify('+90 days');

        $rows = SubscriptionRecord::query()
            ->toBase()
            ->select('subscriptions.*')
            ->join('subscription_periods', 'subscription_periods.id', '=', 'subscriptions.current_period_id')
            ->whereIn('subscriptions.status', ['trialing', 'active', 'past_due', 'paused'])
            ->where('subscription_periods.ends_at', '<=', $horizon)
            ->orderBy('subscription_periods.ends_at')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $records = SubscriptionRecord::hydrate($rows);

        $items = [];
        foreach ($records as $record) {
            $items[] = $this->toDomain($record);
        }

        // An incomplete contract expires on its own clock, whether its
        // period is over or not.
        $remaining = $limit - count($items);

        if ($remaining > 0) {
            $incompleteRows = SubscriptionRecord::query()
                ->toBase()
                ->where('status', 'incomplete')
                ->orderBy('created_at')
                ->limit($remaining)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();

            foreach (SubscriptionRecord::hydrate($incompleteRows) as $record) {
                $items[] = $this->toDomain($record);
            }
        }

        return $items;
    }

    /**
     * The contract does not exist yet: it is created.
     */
    private function insert(Subscription $subscription): void
    {
        try {
            // SAVEPOINT (nested transaction) — a caught violation must not
            // poison the outer transaction of the use case.
            DB::transaction(function () use ($subscription): void {
                SubscriptionRecord::query()->create(
                    ['id' => $subscription->id, 'created_at' => $subscription->createdAt]
                    + $this->toAttributes($subscription),
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->liveContractTaken();
        }

        $this->expected[$subscription->id] = [
            'status' => $subscription->status->value,
            'version' => $subscription->version,
        ];
    }

    /**
     * ADR-0072 §1 — a customer holds at most one live contract per
     * plan: the partial unique index `subscriptions_live_unique` says so.
     */
    private function liveContractTaken(): InvalidSubscriptionTransition
    {
        return new InvalidSubscriptionTransition(
            'A customer holds at most one live contract per plan (ADR-0072 §1).',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(Subscription $subscription): array
    {
        return [
            'project_id' => $subscription->projectId,
            'customer_id' => $subscription->customerId,
            'plan_id' => $subscription->planId,
            'plan_version_id' => $subscription->planVersionId,
            'status' => $subscription->status->value,
            'price_minor' => $subscription->priceMinor,
            'currency' => $subscription->currency,
            'interval_unit' => $subscription->interval->unit->value,
            'interval_count' => $subscription->interval->count,
            'anchor_at' => $subscription->anchorAt,
            'current_period_id' => $subscription->currentPeriodId,
            'trial_ends_at' => $subscription->trialEndsAt,
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd,
            'pending_plan_version_id' => $subscription->pendingPlanVersionId,
            'paused_at' => $subscription->pausedAt,
            'canceled_at' => $subscription->canceledAt,
            'ended_at' => $subscription->endedAt,
            'version' => $subscription->version,
            'updated_at' => $subscription->updatedAt,
        ];
    }

    private function toDomain(SubscriptionRecord $record): Subscription
    {
        return new Subscription(
            id: $record->id,
            projectId: $record->project_id,
            customerId: $record->customer_id,
            planId: $record->plan_id,
            planVersionId: $record->plan_version_id,
            status: SubscriptionStatus::from($record->status),
            priceMinor: $record->price_minor,
            currency: $record->currency,
            interval: new BillingInterval(BillingIntervalUnit::from($record->interval_unit), $record->interval_count),
            anchorAt: DateTimeImmutable::createFromInterface($record->anchor_at),
            currentPeriodId: $record->current_period_id,
            trialEndsAt: $record->trial_ends_at === null ? null : DateTimeImmutable::createFromInterface($record->trial_ends_at),
            cancelAtPeriodEnd: $record->cancel_at_period_end,
            pendingPlanVersionId: $record->pending_plan_version_id,
            pausedAt: $record->paused_at === null ? null : DateTimeImmutable::createFromInterface($record->paused_at),
            canceledAt: $record->canceled_at === null ? null : DateTimeImmutable::createFromInterface($record->canceled_at),
            endedAt: $record->ended_at === null ? null : DateTimeImmutable::createFromInterface($record->ended_at),
            version: $record->version,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
        );
    }
}
