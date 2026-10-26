<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Access\AccessScope;
use Core\Webhook\Contracts\DeadWebhookDeliveryPage;
use Core\Webhook\Contracts\DeadWebhookDeliveryView;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;

final readonly class ListDeadWebhookDeliveriesUseCase implements ListDeadWebhookDeliveries
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
    ) {}

    public function list(ListDeadWebhookDeliveriesCommand $command, AccessScope $scope): DeadWebhookDeliveryPage
    {
        // ADR-0069 §2 — an explicit project outside the account's scope
        // yields nothing rather than another tenant's dead deliveries.
        if ($command->projectId !== null && ! $scope->allowsProject($command->projectId)) {
            return new DeadWebhookDeliveryPage([], 0, $command->page, $command->perPage);
        }

        $result = $this->deliveries->findByStatusPaginated(
            WebhookDeliveryStatus::Dead,
            $command->projectId,
            $command->page,
            $command->perPage,
            // The scope's project list narrows the query itself — never
            // the controller.
            $scope->projectFilter(),
        );

        $items = array_map(
            static fn (WebhookDelivery $delivery): DeadWebhookDeliveryView => new DeadWebhookDeliveryView(
                id: $delivery->id,
                endpointId: $delivery->endpointId,
                projectId: $delivery->projectId,
                eventId: $delivery->eventId,
                eventType: $delivery->eventType,
                payload: $delivery->payload,
                attempts: $delivery->attempts,
                lastError: $delivery->lastError,
                lastResponseStatus: $delivery->lastResponseStatus,
                lastResponseBody: $delivery->lastResponseBody,
                lastAttemptAt: $delivery->lastAttemptAt,
            ),
            $result['items'],
        );

        return new DeadWebhookDeliveryPage($items, $result['total'], $command->page, $command->perPage);
    }
}
