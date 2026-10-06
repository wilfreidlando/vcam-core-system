<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

interface GetWebhookDeliveryDetails
{
    public function forDelivery(string $deliveryId): ?WebhookDeliveryDetailView;

    /**
     * Every delivery of these outbox events (the fan-out and its replays).
     *
     * @param list<string> $eventIds
     * @return list<WebhookDeliveryDetailView>
     */
    public function forEvents(array $eventIds): array;
}
