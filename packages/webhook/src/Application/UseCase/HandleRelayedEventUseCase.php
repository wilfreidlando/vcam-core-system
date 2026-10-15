<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Events\Contracts\DomainEvent;
use Core\Support\Identifier;
use Core\Webhook\Contracts\HandleRelayedEvent;
use Core\Webhook\Domain\Exception\WebhookDeliveryAlreadyExists;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

/**
 * Fan-out: one republished outbox event becomes one `WebhookDelivery` row
 * per active, subscribed endpoint of its Project. Idempotent by
 * construction (ADR-0040 §4) — a `WebhookDeliveryAlreadyExists` race for a
 * given `(endpoint, event)` pair is treated as "already fanned out", not
 * an error, so the at-least-once relay never produces a duplicate delivery.
 */
final readonly class HandleRelayedEventUseCase implements HandleRelayedEvent
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private WebhookDeliveryRepository $deliveries,
        private Identifier $identifier,
    ) {}

    public function handle(DomainEvent $event): array
    {
        $deliveryIds = [];

        foreach ($this->endpoints->findActiveForProject($event->projectId) as $endpoint) {
            if (! $endpoint->subscribesTo($event->type)) {
                continue;
            }

            $delivery = new WebhookDelivery(
                id: $this->identifier->generate(),
                endpointId: $endpoint->id,
                projectId: $event->projectId,
                eventId: $event->id,
                eventType: $event->type,
                payload: $event->payload,
                status: WebhookDeliveryStatus::Pending,
                attempts: 0,
                nextRetryAt: null,
                lastAttemptAt: null,
                lastError: null,
                deliveredAt: null,
            );

            try {
                $this->deliveries->create($delivery);
            } catch (WebhookDeliveryAlreadyExists) {
                continue;
            }

            $deliveryIds[] = $delivery->id;
        }

        return $deliveryIds;
    }
}
