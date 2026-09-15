<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

interface RegisterWebhookEndpoint
{
    public function register(RegisterWebhookEndpointCommand $command): RegisteredWebhookEndpointView;
}
