<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionKind;
use Core\Payment\Domain\Model\MoneyExceptionResolution;
use Core\Payment\Domain\Model\MoneyExceptionSeverity;
use Core\Payment\Domain\Model\MoneyExceptionStatus;
use Core\Payment\Domain\Port\MoneyExceptionRepository;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentMoneyExceptionRepository implements MoneyExceptionRepository
{
    public function create(MoneyException $exception): bool
    {
        try {
            // SAVEPOINT: the caller is usually inside a wider transaction
            // (the payment status handler); a caught violation must not
            // poison it (same reason as EloquentPaymentRepository::create()).
            DB::transaction(function () use ($exception): void {
                MoneyExceptionRecord::query()->create($this->toAttributes($exception));
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function save(MoneyException $exception): void
    {
        $attributes = $this->toAttributes($exception);
        unset($attributes['id'], $attributes['created_at']);
        $attributes['details'] = json_encode($exception->details, JSON_THROW_ON_ERROR);

        MoneyExceptionRecord::query()->whereKey($exception->id)->toBase()->update($attributes);
    }

    public function find(string $id): ?MoneyException
    {
        $record = MoneyExceptionRecord::query()->find($id);

        return $record instanceof MoneyExceptionRecord ? $this->toDomain($record) : null;
    }

    public function findForUpdate(string $id): ?MoneyException
    {
        $row = MoneyExceptionRecord::query()->whereKey($id)->toBase()->lockForUpdate()->first();
        if ($row === null) {
            return null;
        }

        return $this->toDomain(MoneyExceptionRecord::hydrate([(array) $row])->sole());
    }

    public function list(?array $projectIds, ?MoneyExceptionStatus $status, int $page, int $perPage): array
    {
        $base = MoneyExceptionRecord::query()->toBase();
        if ($projectIds !== null) {
            $base->whereIn('project_id', $projectIds);
        }
        if ($status !== null) {
            $base->where('status', $status->value);
        }

        $total = (clone $base)->count();

        // Unresolved first, then by severity, then newest — what an
        // operator must look at comes first. Primary key as tiebreaker.
        $base->orderByRaw("CASE status WHEN 'awaiting_approval' THEN 0 WHEN 'open' THEN 1 ELSE 2 END");
        $base->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END");
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $items = [];
        foreach (MoneyExceptionRecord::hydrate($rows) as $record) {
            $items[] = $this->toDomain($record);
        }

        return ['items' => $items, 'total' => $total];
    }

    public function countUnresolved(?array $projectIds): int
    {
        $base = MoneyExceptionRecord::query()->toBase();
        $base->whereIn('status', [MoneyExceptionStatus::Open->value, MoneyExceptionStatus::AwaitingApproval->value]);
        if ($projectIds !== null) {
            $base->whereIn('project_id', $projectIds);
        }

        return $base->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(MoneyException $exception): array
    {
        return [
            'id' => $exception->id,
            'project_id' => $exception->projectId,
            'kind' => $exception->kind->value,
            'severity' => $exception->severity->value,
            'amount_minor' => $exception->amount,
            'currency' => $exception->currency,
            'booked_amount_minor' => $exception->bookedAmount,
            'payment_id' => $exception->paymentId,
            'subject_ref' => $exception->subjectRef,
            'summary' => $exception->summary,
            'details' => $exception->details,
            'status' => $exception->status->value,
            'resolution' => $exception->resolution?->value,
            'resolution_reference' => $exception->resolutionReference,
            'resolution_note' => $exception->resolutionNote,
            'resolved_by' => $exception->resolvedBy,
            'approved_by' => $exception->approvedBy,
            'created_at' => $exception->createdAt,
            'updated_at' => new DateTimeImmutable,
            'resolved_at' => $exception->resolvedAt,
        ];
    }

    private function toDomain(MoneyExceptionRecord $record): MoneyException
    {
        return new MoneyException(
            id: $record->id,
            projectId: $record->project_id,
            kind: MoneyExceptionKind::from($record->kind),
            severity: MoneyExceptionSeverity::from($record->severity),
            amount: $record->amount_minor,
            currency: $record->currency,
            bookedAmount: $record->booked_amount_minor,
            paymentId: $record->payment_id,
            subjectRef: $record->subject_ref,
            summary: $record->summary,
            details: $record->details,
            status: MoneyExceptionStatus::from($record->status),
            resolution: $record->resolution === null ? null : MoneyExceptionResolution::from($record->resolution),
            resolutionReference: $record->resolution_reference,
            resolutionNote: $record->resolution_note,
            resolvedBy: $record->resolved_by,
            approvedBy: $record->approved_by,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            resolvedAt: $record->resolved_at === null ? null : DateTimeImmutable::createFromInterface($record->resolved_at),
        );
    }
}
