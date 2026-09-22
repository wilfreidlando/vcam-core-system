<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

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

    public function list(ListWebhookDeliveriesCommand $command): WebhookDeliveryPage
    {
        $status = $command->status !== null ? WebhookDeliveryStatus::tryFrom($command->status) : null;

        $result = $this->deliveries->findByStatusPaginated(
            $status,
            $command->projectId,
            $command->page,
            $command->perPage,
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
