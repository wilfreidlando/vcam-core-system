<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Application\Port\PlanVersionDirectory;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Model\BillingInterval;
use Core\Billing\Domain\Model\BillingIntervalUnit;
use Core\Billing\Domain\Model\PlanFeatures;
use Core\Billing\Domain\Model\PlanVersion;
use Core\Billing\Domain\Model\PlanVersionStatus;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use stdClass;

/**
 * ADR-0071 §1 — the terms of a plan.
 *
 * A published version is frozen, twice: by the domain (ADR-0071 §2) and
 * by the `plan_version_immutable` trigger of the database. The trigger
 * raises a `check_violation`; it is translated here into the domain
 * exception, so the caller never sees a raw driver error.
 */
final class EloquentPlanVersionRepository implements PlanVersionDirectory
{
    public function findByPlanAndVersion(string $planId, int $version): ?PlanVersion
    {
        $record = PlanVersionRecord::query()
            ->where('plan_id', $planId)
            ->where('version', $version)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findLatestPublished(string $planId): ?PlanVersion
    {
        // `toBase()` — `orderByDesc()` is forwarded by Eloquent's builder
        // to the query builder rather than declared on it, which trips a
        // Larastan false positive (the same workaround as
        // EloquentPaymentRepository). `hydrate()` restores the casts.
        $base = PlanVersionRecord::query()
            ->where('plan_id', $planId)
            ->where('status', PlanVersionStatus::Published->value)
            ->toBase();
        $base->orderByDesc('version');

        $row = $base->first();

        if ($row === null) {
            return null;
        }

        return $this->toDomain(PlanVersionRecord::hydrate([(array) $row])->sole());
    }

    public function findById(string $id): ?PlanVersion
    {
        $record = PlanVersionRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function nextVersionNumber(string $planId): int
    {
        // `toBase()` — `max()` is forwarded by Eloquent's builder to the
        // query builder rather than declared on it (the same workaround
        // as EloquentPaymentRepository).
        $highest = PlanVersionRecord::query()
            ->where('plan_id', $planId)
            ->toBase()
            ->max('version');

        if (! is_numeric($highest)) {
            return 1;
        }

        return ((int) $highest) + 1;
    }

    public function save(PlanVersion $version): void
    {
        try {
            // SAVEPOINT (nested transaction) — a caught violation must not
            // poison the outer transaction of the use case.
            DB::transaction(function () use ($version): void {
                $attributes = $this->toAttributes($version);

                // `toBase()` — `exists()` is forwarded by Eloquent's
                // builder to the query builder rather than declared on
                // it, which trips a Larastan false positive (the same
                // workaround as EloquentPaymentRepository).
                if (PlanVersionRecord::query()->whereKey($version->id)->toBase()->exists()) {
                    PlanVersionRecord::query()->whereKey($version->id)->update($attributes);
                } else {
                    PlanVersionRecord::query()->create(
                        ['id' => $version->id, 'created_at' => $version->createdAt] + $attributes,
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            // `plan_versions_plan_version_unique` (the rank is taken) or
            // `plan_versions_one_draft` (the plan already has a draft).
            // Both mean the same thing to the caller: the terms must
            // change on ANOTHER version.
            throw PlanVersionNotEditable::becausePublished($version->version);
        } catch (QueryException $exception) {
            // The `plan_version_immutable` trigger raises a
            // `check_violation` (SQLSTATE 23514) — the same rule, stated
            // by the database.
            if ($this->isCheckViolation($exception)) {
                throw PlanVersionNotEditable::becausePublished($version->version);
            }

            throw $exception;
        }
    }

    /**
     * ADR-0071 §1 — every version of a plan, oldest first: the history
     * the catalogue shows next to a plan.
     *
     * @return list<PlanVersion>
     */
    public function listByPlan(string $planId): array
    {
        // See EloquentCustomerRepository::listByProject() for why the whole
        // query is built on `toBase()`.
        $rows = PlanVersionRecord::query()
            ->toBase()
            ->where('plan_id', $planId)
            ->orderBy('version')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $records = PlanVersionRecord::hydrate($rows);

        $versions = [];
        foreach ($records as $record) {
            $versions[] = $this->toDomain($record);
        }

        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(PlanVersion $version): array
    {
        return [
            'plan_id' => $version->planId,
            'project_id' => $version->projectId,
            'version' => $version->version,
            'price_minor' => $version->priceMinor,
            'currency' => $version->currency,
            'interval_unit' => $version->interval->unit->value,
            'interval_count' => $version->interval->count,
            'trial_days' => $version->trialDays,
            'grace_days' => $version->graceDays,
            'grace_access' => $version->graceAccess,
            'features' => $this->features($version->features->toArray()),
            'status' => $version->status->value,
            'published_at' => $version->publishedAt,
        ];
    }

    private function toDomain(PlanVersionRecord $record): PlanVersion
    {
        return new PlanVersion(
            id: $record->id,
            planId: $record->plan_id,
            projectId: $record->project_id,
            version: $record->version,
            priceMinor: $record->price_minor,
            currency: $record->currency,
            interval: new BillingInterval(BillingIntervalUnit::from($record->interval_unit), $record->interval_count),
            trialDays: $record->trial_days,
            graceDays: $record->grace_days,
            graceAccess: $record->grace_access,
            features: PlanFeatures::fromArray($this->featuresOf($record->features)),
            status: PlanVersionStatus::from($record->status),
            publishedAt: $record->published_at === null ? null : DateTimeImmutable::createFromInterface($record->published_at),
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
        );
    }

    /**
     * The features as a JSON **object** — the column refuses a JSON
     * array (`plan_versions_features_object_check`), and an empty map
     * would be stored as `[]`: it is written as an empty object instead.
     *
     * @param array<string, array<string, mixed>> $features
     */
    private function features(array $features): string
    {
        return (string) json_encode($features === [] ? new stdClass : $features);
    }

    /**
     * The features as a map — the column holds a JSON **object**
     * (ADR-0071 §3).
     *
     * @return array<string, mixed>
     */
    private function featuresOf(string $json): array
    {
        $decoded = json_decode($json, true);
        /** @var array<string, mixed> $features */
        $features = is_array($decoded) ? $decoded : [];

        return $features;
    }

    /**
     * The SQLSTATE of the driver error: Laravel's `QueryException` keeps
     * the code of the underlying `PDOException`, not its own.
     */
    private function isCheckViolation(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();
        $code = $previous instanceof PDOException ? $previous->getCode() : $exception->getCode();

        return (string) $code === '23514';
    }
}
