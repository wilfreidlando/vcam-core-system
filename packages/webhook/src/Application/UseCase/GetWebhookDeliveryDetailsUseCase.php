<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Webhook\Contracts\GetWebhookDeliveryDetails;
use Core\Webhook\Contracts\WebhookDeliveryAttemptView;
use Core\Webhook\Contracts\WebhookDeliveryDetailView;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryAttempt;
use Core\Webhook\Domain\Port\WebhookDeliveryAttemptRepository;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use DateTimeInterface;

final readonly class GetWebhookDeliveryDetailsUseCase implements GetWebhookDeliveryDetails
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private WebhookDeliveryAttemptRepository $attempts,
        private WebhookEndpointRepository $endpoints,
    ) {}

    public function forDelivery(string $deliveryId): ?WebhookDeliveryDetailView
    {
        $delivery = $this->deliveries->find($deliveryId);

        return $delivery === null ? null : ($this->views([$delivery])[0] ?? null);
    }

    public function forEvents(array $eventIds): array
    {
        return $this->views($this->deliveries->findByEventIds($eventIds));
    }

    /**
     * @param list<WebhookDelivery> $deliveries
     * @return list<WebhookDeliveryDetailView>
     */
    private function views(array $deliveries): array
    {
        $attempts = $this->attempts->forDeliveries(array_map(static fn (WebhookDelivery $d): string => $d->id, $deliveries));

        $views = [];
        foreach ($deliveries as $delivery) {
            $endpoint = $this->endpoints->find($delivery->endpointId);

            $views[] = new WebhookDeliveryDetailView(
                id: $delivery->id,
                endpointId: $delivery->endpointId,
                endpointUrl: $endpoint?->url,
                endpointActive: $endpoint?->active,
                projectId: $delivery->projectId,
                eventId: $delivery->eventId,
                eventType: $delivery->eventType,
                payload: $delivery->payload,
                status: $delivery->status->value,
                attemptCount: $delivery->attempts,
                nextRetryAt: $delivery->nextRetryAt?->format(DateTimeInterface::RFC3339),
                lastAttemptAt: $delivery->lastAttemptAt?->format(DateTimeInterface::RFC3339),
                deliveredAt: $delivery->deliveredAt?->format(DateTimeInterface::RFC3339),
                lastError: $delivery->lastError,
                replayedFromId: $delivery->replayedFromId,
                attempts: array_map(static fn (WebhookDeliveryAttempt $a): WebhookDeliveryAttemptView => new WebhookDeliveryAttemptView(
                    attempt: $a->attempt,
                    attemptedAt: $a->attemptedAt->format(DATE_RFC3339_EXTENDED),
                    url: $a->url,
                    requestHeaders: $a->requestHeaders,
                    requestBody: $a->requestBody,
                    responseStatus: $a->responseStatus,
                    responseBody: $a->responseBody,
                    durationMs: $a->durationMs,
                    error: $a->error,
                    outcome: $a->outcome,
                    correlationId: $a->correlationId,
                    traceId: $a->traceId,
                ), $attempts[$delivery->id] ?? []),
            );
        }

        return $views;
    }
}
