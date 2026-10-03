<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Core\Webhook\Domain\Exception\WebhookDeliveryAlreadyExists;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class EloquentWebhookDeliveryRepository implements WebhookDeliveryRepository
{
    public function create(WebhookDelivery $delivery): void
    {
        try {
            // SAVEPOINT (nested transaction) — without this, a caught
            // violation poisons the whole outer PostgreSQL transaction
            // (same reasoning as EloquentPaymentRepository::create()).
            DB::transaction(function () use ($delivery): void {
                WebhookDeliveryRecord::query()->create($this->toAttributes($delivery));
            });
        } catch (UniqueConstraintViolationException) {
            throw WebhookDeliveryAlreadyExists::forEndpointAndEvent($delivery->endpointId, $delivery->eventId);
        }
    }

    public function save(WebhookDelivery $delivery): void
    {
        WebhookDeliveryRecord::query()->whereKey($delivery->id)->update($this->toAttributes($delivery));
    }

    public function find(string $id): ?WebhookDelivery
    {
        $record = WebhookDeliveryRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findStalledPending(DateTimeImmutable $overdueBefore, int $limit): array
    {
        $rows = WebhookDeliveryRecord::query()
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->where(function ($query) use ($overdueBefore): void {
                $query->where('next_retry_at', '<', $overdueBefore)
                    ->orWhere(function ($inner) use ($overdueBefore): void {
                        $inner->whereNull('next_retry_at')->where('created_at', '<', $overdueBefore);
                    });
            })
            ->toBase()
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $rows = WebhookDeliveryRecord::hydrate(array_map(static fn (mixed $row): array => (array) $row, $rows->all()));

        return array_values($rows->map(fn (WebhookDeliveryRecord $record): WebhookDelivery => $this->toDomain($record))->all());
    }

    public function findByStatusPaginated(?WebhookDeliveryStatus $status, ?string $projectId, int $page, int $perPage): array
    {
        $query = WebhookDeliveryRecord::query();

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder to
        // the underlying query builder rather than declared on it directly;
        // going through `toBase()` first avoids a Larastan false positive
        // ("dynamic call to static method") on those forwarded calls, and
        // `WebhookDeliveryRecord::hydrate()` restores the model casts after.
        $base = $query->toBase();

        $total = (clone $base)->count();

        // Tiebreaker on the primary key — see EloquentNotificationRepository's
        // identical comment: without it, rows sharing a created_at can
        // duplicate or vanish across pages.
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = WebhookDeliveryRecord::hydrate($rows);

        $items = [];
        foreach ($records as $record) {
            $items[] = $this->toDomain($record);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'endpoint_id' => $delivery->endpointId,
            'project_id' => $delivery->projectId,
            'event_id' => $delivery->eventId,
            'event_type' => $delivery->eventType,
            'payload' => $delivery->payload,
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'next_retry_at' => $delivery->nextRetryAt,
            'last_attempt_at' => $delivery->lastAttemptAt,
            'last_error' => $delivery->lastError,
            'last_response_status' => $delivery->lastResponseStatus,
            'last_response_body' => $delivery->lastResponseBody,
            'delivered_at' => $delivery->deliveredAt,
            'replayed_from_id' => $delivery->replayedFromId,
        ];
    }

    private function toDomain(WebhookDeliveryRecord $record): WebhookDelivery
    {
        return new WebhookDelivery(
            id: $record->id,
            endpointId: $record->endpoint_id,
            projectId: $record->project_id,
            eventId: $record->event_id,
            eventType: $record->event_type,
            payload: $record->payload,
            status: WebhookDeliveryStatus::from($record->status),
            attempts: $record->attempts,
            nextRetryAt: $record->next_retry_at,
            lastAttemptAt: $record->last_attempt_at,
            lastError: $record->last_error,
            deliveredAt: $record->delivered_at,
            replayedFromId: $record->replayed_from_id,
            lastResponseStatus: $record->last_response_status,
            lastResponseBody: $record->last_response_body,
        );
    }
}
