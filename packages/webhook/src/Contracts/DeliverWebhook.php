<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

interface DeliverWebhook
{
    public function deliver(DeliverWebhookCommand $command): WebhookDeliveryOutcome;
}
