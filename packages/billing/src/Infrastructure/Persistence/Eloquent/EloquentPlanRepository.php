<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Application\Port\PlanDirectory;
use Core\Billing\Domain\Exception\PlanCodeAlreadyTaken;
use Core\Billing\Domain\Model\Plan;
use Core\Billing\Domain\Model\PlanStatus;
use Core\Billing\Domain\Model\PlanVisibility;
use Core\Billing\Infrastructure\Persistence\Cursor;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0071 §1 — the catalogue of a project.
 *
 * Only the identity of the plan lives here: the code the project chose
 * and never changes, its name, its visibility and its rank. The **terms**
 * belong to the versions.
 */
final class EloquentPlanRepository implements PlanDirectory
{
    /**
     * ADR-0088 §2 — a listing never returns more than a hundred rows.
     */
    private const MAX_LIMIT = 100;

    public function findByCode(string $projectId, string $code): ?Plan
    {
        $record = PlanRecord::query()
            ->where('project_id', $projectId)
            ->where('code', $code)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findById(string $id): ?Plan
    {
        $record = PlanRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function save(Plan $plan): void
    {
        try {
            // SAVEPOINT (nested transaction) — a caught unique violation
            // must not poison the outer transaction of the use case (the
            // same comment as EloquentPaymentRepository::create()).
            DB::transaction(function () use ($plan): void {
                $attributes = $this->toAttributes($plan);

                // `toBase()` — `exists()` is forwarded by Eloquent's
                // builder to the query builder rather than declared on
                // it, which trips a Larastan false positive (the same
                // workaround as EloquentPaymentRepository).
                if (PlanRecord::query()->whereKey($plan->id)->toBase()->exists()) {
                    PlanRecord::query()
                        ->whereKey($plan->id)
                        ->update($attributes);
                } else {
                    PlanRecord::query()->create(
                        ['id' => $plan->id, 'created_at' => $plan->createdAt] + $attributes,
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            // `plans_project_code_unique` — the code is the key the
            // catalogue is read by, and it is unique per project.
            throw PlanCodeAlreadyTaken::inProject($plan->projectId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * ADR-0088 §2 — the catalogue of a project, page after page.
     *
     * @return array{items: list<Plan>, nextCursor: ?string}
     */
    public function listByProject(
        string $projectId,
        ?string $status,
        ?string $cursor,
        int $limit,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // See EloquentCustomerRepository::listByProject() for why the whole
        // query is built on `toBase()`.
        $base = PlanRecord::query()->toBase()->where('project_id', $projectId);

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

        $records = PlanRecord::hydrate($rows);

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
     * @return array<string, mixed>
     */
    private function toAttributes(Plan $plan): array
    {
        return [
            'project_id' => $plan->projectId,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'status' => $plan->status->value,
            'visibility' => $plan->visibility->value,
            'sort_order' => $plan->sortOrder,
            // The instant of the last change is the domain's, never the
            // clock of the database (ADR-0071 §1).
            'updated_at' => $plan->updatedAt,
        ];
    }

    private function toDomain(PlanRecord $record): Plan
    {
        return new Plan(
            id: $record->id,
            projectId: $record->project_id,
            code: $record->code,
            name: $record->name,
            description: $record->description,
            status: PlanStatus::from($record->status),
            visibility: PlanVisibility::from($record->visibility),
            sortOrder: $record->sort_order,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
        );
    }
}
