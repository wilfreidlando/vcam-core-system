<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Port;

use Core\Webhook\Domain\Model\WebhookDeliveryAttempt;

interface WebhookDeliveryAttemptRepository
{
    public function record(WebhookDeliveryAttempt $attempt): void;

    /**
     * @param list<string> $deliveryIds
     * @return array<string, list<WebhookDeliveryAttempt>> delivery id => attempts, oldest first
     */
    public function forDeliveries(array $deliveryIds): array;
}
