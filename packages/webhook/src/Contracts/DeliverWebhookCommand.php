<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

final readonly class DeliverWebhookCommand
{
    public function __construct(
        public string $deliveryId,
    ) {}
}
