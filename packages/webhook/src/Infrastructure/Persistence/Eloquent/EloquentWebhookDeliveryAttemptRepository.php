<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Core\Support\Observability\TraceContext;
use Core\Webhook\Domain\Model\WebhookDeliveryAttempt;
use Core\Webhook\Domain\Port\WebhookDeliveryAttemptRepository;

final class EloquentWebhookDeliveryAttemptRepository implements WebhookDeliveryAttemptRepository
{
    public function record(WebhookDeliveryAttempt $attempt): void
    {
        WebhookDeliveryAttemptRecord::query()->create([
            'id' => $attempt->id,
            'delivery_id' => $attempt->deliveryId,
            'attempt' => $attempt->attempt,
            'attempted_at' => $attempt->attemptedAt,
            'url' => $attempt->url,
            'request_headers' => $attempt->requestHeaders,
            'request_body' => $attempt->requestBody,
            'response_status' => $attempt->responseStatus,
            'response_body' => $attempt->responseBody,
            'duration_ms' => $attempt->durationMs,
            'error' => $attempt->error,
            'outcome' => $attempt->outcome,
            'correlation_id' => $attempt->correlationId ?? TraceContext::correlationId(),
            'trace_id' => $attempt->traceId ?? TraceContext::traceId(),
        ]);
    }

    public function forDeliveries(array $deliveryIds): array
    {
        if ($deliveryIds === []) {
            return [];
        }

        $rows = WebhookDeliveryAttemptRecord::query()
            ->toBase()
            ->whereIn('delivery_id', $deliveryIds)
            ->orderBy('attempted_at')
            ->get();

        $records = WebhookDeliveryAttemptRecord::hydrate(array_map(static fn (mixed $row): array => (array) $row, $rows->all()));

        $grouped = [];
        foreach ($records as $record) {
            $grouped[$record->delivery_id][] = new WebhookDeliveryAttempt(
                id: $record->id,
                deliveryId: $record->delivery_id,
                attempt: $record->attempt,
                attemptedAt: $record->attempted_at,
                url: $record->url,
                requestHeaders: $record->request_headers,
                requestBody: $record->request_body,
                responseStatus: $record->response_status,
                responseBody: $record->response_body,
                durationMs: $record->duration_ms,
                error: $record->error,
                outcome: $record->outcome,
                correlationId: $record->correlation_id,
                traceId: $record->trace_id,
            );
        }

        return $grouped;
    }
}
