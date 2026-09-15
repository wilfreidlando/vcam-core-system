<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Identifier;
use Core\Webhook\Contracts\ReplayedWebhookDeliveryView;
use Core\Webhook\Contracts\ReplayWebhookDelivery;
use Core\Webhook\Contracts\ReplayWebhookDeliveryCommand;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotDead;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;

/**
 * CLI-only for now (`webhook:replay`) — the Core Admin control ADR-0009
 * describes does not exist before Lot 6 (ADR-0040 §7). Creates a fresh
 * delivery row (attempts reset to zero) for the same `(endpoint, event)`
 * pair rather than mutating the dead one, so the original attempt history
 * stays intact for audit.
 */
final readonly class ReplayWebhookDeliveryUseCase implements ReplayWebhookDelivery
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private Identifier $identifier,
    ) {}

    public function replay(ReplayWebhookDeliveryCommand $command): ReplayedWebhookDeliveryView
    {
        $dead = $this->deliveries->find($command->deliveryId);
        if ($dead === null) {
            throw WebhookDeliveryNotFound::withId($command->deliveryId);
        }

        if ($dead->status !== WebhookDeliveryStatus::Dead) {
            throw WebhookDeliveryNotDead::withId($command->deliveryId);
        }

        $fresh = new WebhookDelivery(
            id: $this->identifier->generate(),
            endpointId: $dead->endpointId,
            projectId: $dead->projectId,
            eventId: $dead->eventId,
            eventType: $dead->eventType,
            payload: $dead->payload,
            status: WebhookDeliveryStatus::Pending,
            attempts: 0,
            nextRetryAt: null,
            lastAttemptAt: null,
            lastError: null,
            deliveredAt: null,
            replayedFromId: $dead->id,
        );

        $this->deliveries->create($fresh);

        return new ReplayedWebhookDeliveryView($fresh->id);
    }
}
