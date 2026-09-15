<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use RuntimeException;

interface DeactivateWebhookEndpoint
{
    /**
     * @throws RuntimeException when the endpoint does not exist, or belongs to another project
     */
    public function deactivate(DeactivateWebhookEndpointCommand $command): void;
}
