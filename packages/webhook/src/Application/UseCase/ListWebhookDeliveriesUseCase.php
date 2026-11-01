<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Access\AccessScope;
use Core\Webhook\Contracts\ListWebhookDeliveries;
use Core\Webhook\Contracts\ListWebhookDeliveriesCommand;
use Core\Webhook\Contracts\WebhookDeliveryPage;
use Core\Webhook\Contracts\WebhookDeliveryView;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;

final readonly class ListWebhookDeliveriesUseCase implements ListWebhookDeliveries
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
    ) {}

    public function list(ListWebhookDeliveriesCommand $command, AccessScope $scope): WebhookDeliveryPage
    {
        $status = $command->status !== null ? WebhookDeliveryStatus::tryFrom($command->status) : null;

        // ADR-0069 §2 — an explicit project outside the account's scope
        // yields nothing rather than another tenant's deliveries.
        if ($command->projectId !== null && ! $scope->allowsProject($command->projectId)) {
            return new WebhookDeliveryPage([], 0, $command->page, $command->perPage);
        }

        $result = $this->deliveries->findByStatusPaginated(
            $status,
            $command->projectId,
            $command->page,
            $command->perPage,
            // The scope's project list is applied in the query, never in
            // the controller: a platform scope has no restriction (null),
            // an ordinary one narrows to its own projects.
            $scope->projectFilter(),
        );

        $items = array_map(
            static fn (WebhookDelivery $delivery): WebhookDeliveryView => new WebhookDeliveryView(
                id: $delivery->id,
                endpointId: $delivery->endpointId,
                projectId: $delivery->projectId,
                eventId: $delivery->eventId,
                eventType: $delivery->eventType,
                payload: $delivery->payload,
                status: $delivery->status->value,
                attempts: $delivery->attempts,
                lastError: $delivery->lastError,
                lastResponseStatus: $delivery->lastResponseStatus,
                lastResponseBody: $delivery->lastResponseBody,
                lastAttemptAt: $delivery->lastAttemptAt,
                deliveredAt: $delivery->deliveredAt,
            ),
            $result['items'],
        );

        return new WebhookDeliveryPage($items, $result['total'], $command->page, $command->perPage);
    }
}
