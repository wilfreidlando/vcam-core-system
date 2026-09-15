<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class ReplayedWebhookDeliveryView
{
    public function __construct(
        public string $newDeliveryId,
    ) {}
}
