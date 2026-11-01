<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Events\Contracts\DomainEvent;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Support\Access\AccessScope;
use Core\Support\Identifier;
use Core\Webhook\Contracts\HandleRelayedEvent;
use Core\Webhook\Domain\Exception\WebhookDeliveryAlreadyExists;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use RuntimeException;

/**
 * Fan-out: one republished outbox event becomes one `WebhookDelivery` row
 * per active, subscribed endpoint of its Project. Idempotent by
 * construction (ADR-0040 §4) — a `WebhookDeliveryAlreadyExists` race for a
 * given `(endpoint, event)` pair is treated as "already fanned out", not
 * an error, so the at-least-once relay never produces a duplicate delivery.
 *
 * ADR-0069 §3 — an archived project's endpoints are disabled: nothing is
 * fanned out for it. A suspended project keeps receiving its webhooks (the
 * money that was already moving keeps being reported).
 */
final readonly class HandleRelayedEventUseCase implements HandleRelayedEvent
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private WebhookDeliveryRepository $deliveries,
        private Identifier $identifier,
        private GetProject $projects,
    ) {}

    public function handle(DomainEvent $event): array
    {
        if ($this->isArchived($event->projectId)) {
            return [];
        }

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

    private function isArchived(string $projectId): bool
    {
        try {
            // The event already names its project: the lookup is scoped to
            // exactly that project.
            return $this->projects->get(new GetProjectCommand($projectId), AccessScope::forProject($projectId))->status === 'archived';
        } catch (RuntimeException) {
            // An unknown project has no endpoint to fan out to anyway.
            return false;
        }
    }
}
