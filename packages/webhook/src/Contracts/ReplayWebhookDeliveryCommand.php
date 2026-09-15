<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class ReplayWebhookDeliveryCommand
{
    public function __construct(
        public string $deliveryId,
    ) {}
}
