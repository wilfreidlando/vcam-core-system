<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

interface ListWebhookEndpoints
{
    /**
     * @return list<WebhookEndpointView>
     */
    public function list(ListWebhookEndpointsCommand $command): array;
}
