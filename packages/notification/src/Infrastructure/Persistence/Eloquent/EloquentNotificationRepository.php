<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Core\Notification\Domain\Exception\NotificationAlreadyExists;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;
use Core\Support\Environment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentNotificationRepository implements NotificationRepository
{
    public function create(Notification $notification): void
    {
        try {
            // SAVEPOINT (nested transaction) — mirrors EloquentPaymentRepository::create():
            // without this, a caught violation poisons the whole outer transaction.
            DB::transaction(function () use ($notification): void {
                NotificationRecord::query()->create($this->toAttributes($notification));
            });
        } catch (UniqueConstraintViolationException) {
            throw new NotificationAlreadyExists;
        }
    }

    public function save(Notification $notification): void
    {
        NotificationRecord::query()->whereKey($notification->id)->update($this->toAttributes($notification));
    }

    public function find(string $id): ?Notification
    {
        $record = NotificationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?Notification
    {
        $record = NotificationRecord::query()
            ->where('project_id', $projectId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByProviderMessage(string $providerCode, string $providerMessageId): ?Notification
    {
        $record = NotificationRecord::query()
            ->where('provider_code', $providerCode)
            ->where('provider_message_id', $providerMessageId)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByProject(string $projectId, ?NotificationStatus $status, int $page, int $perPage): array
    {
        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder
        // to the underlying query builder rather than declared on it
        // directly; going through `toBase()` first avoids a Larastan false
        // positive ("dynamic call to static method") on those forwarded
        // calls, and `NotificationRecord::hydrate()` restores the casts.
        $base = NotificationRecord::query()->where('project_id', $projectId)->toBase();

        if ($status !== null) {
            $base->where('status', $status->value);
        }

        $total = (clone $base)->count();

        // A tiebreaker on the primary key: two rows can share the same
        // created_at (batch insert, clock resolution), and without a
        // deterministic secondary sort a row can appear twice or vanish
        // across page boundaries (review finding, Lot 6 follow-up).
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = NotificationRecord::hydrate($rows);

        $notifications = [];
        foreach ($records as $record) {
            $notifications[] = $this->toDomain($record);
        }

        return ['items' => $notifications, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'project_id' => $notification->projectId,
            'channel' => $notification->channel,
            'to_party_ref' => $notification->toPartyRef,
            'content_type' => $notification->contentType,
            'idempotency_key' => $notification->idempotencyKey,
            'status' => $notification->status->value,
            'provider_code' => $notification->providerCode,
            'provider_message_id' => $notification->providerMessageId,
            'error_message' => $notification->errorMessage,
            'environment' => $notification->environment->value,
        ];
    }

    private function toDomain(NotificationRecord $record): Notification
    {
        return new Notification(
            id: $record->id,
            projectId: $record->project_id,
            channel: $record->channel,
            toPartyRef: $record->to_party_ref,
            contentType: $record->content_type,
            idempotencyKey: $record->idempotency_key,
            status: NotificationStatus::from($record->status),
            providerCode: $record->provider_code,
            providerMessageId: $record->provider_message_id,
            errorMessage: $record->error_message,
            environment: Environment::from($record->environment),
        );
    }
}
