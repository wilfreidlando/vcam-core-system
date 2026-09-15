<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

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

    public function list(ListDeadWebhookDeliveriesCommand $command): DeadWebhookDeliveryPage
    {
        $result = $this->deliveries->findByStatusPaginated(
            WebhookDeliveryStatus::Dead,
            $command->projectId,
            $command->page,
            $command->perPage,
        );

        $items = array_map(
            static fn (WebhookDelivery $delivery): DeadWebhookDeliveryView => new DeadWebhookDeliveryView(
                id: $delivery->id,
                endpointId: $delivery->endpointId,
                projectId: $delivery->projectId,
                eventType: $delivery->eventType,
                attempts: $delivery->attempts,
                lastError: $delivery->lastError,
                lastAttemptAt: $delivery->lastAttemptAt,
            ),
            $result['items'],
        );

        return new DeadWebhookDeliveryPage($items, $result['total'], $command->page, $command->perPage);
    }
}
