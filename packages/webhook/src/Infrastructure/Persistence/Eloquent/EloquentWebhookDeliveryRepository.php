<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Core\Webhook\Domain\Exception\WebhookDeliveryAlreadyExists;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
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
        );
    }
}
