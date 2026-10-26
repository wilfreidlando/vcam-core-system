<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Access\AccessScope;
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

    public function forDelivery(string $deliveryId, AccessScope $scope): ?WebhookDeliveryDetailView
    {
        $delivery = $this->deliveries->find($deliveryId);

        if ($delivery === null) {
            return null;
        }

        // ADR-0069 §2 / S-04 — a delivery of a project outside the
        // account's scope is reported as *not found*, never as forbidden:
        // the answer must not confirm that the resource exists.
        if (! $scope->allowsProject($delivery->projectId)) {
            return null;
        }

        return $this->views([$delivery])[0] ?? null;
    }

    public function forEvents(array $eventIds, AccessScope $scope): array
    {
        // The scope's project list is applied in the query, never in the
        // controller.
        $deliveries = $this->deliveries->findByEventIds($eventIds, $scope->projectFilter());

        // Defence in depth: the query already excludes the foreign
        // projects, but `WebhookDeliveryDetailView` carries the project
        // id — the check is repeated here so a delivery can never leak
        // through this port.
        $visible = array_values(array_filter(
            $deliveries,
            static fn (WebhookDelivery $delivery): bool => $scope->allowsProject($delivery->projectId),
        ));

        return $this->views($visible);
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
