<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use RuntimeException;

interface UpdateWebhookEndpoint
{
    /**
     * Changes the URL and/or subscribed events of an existing endpoint —
     * never its signing secret (rotating that is a separate, more
     * sensitive operation this port does not do) and never its active
     * flag (see `ActivateWebhookEndpoint`/`DeactivateWebhookEndpoint`).
     *
     * @throws RuntimeException when the endpoint does not exist, or belongs to another project
     */
    public function update(UpdateWebhookEndpointCommand $command): WebhookEndpointView;
}
