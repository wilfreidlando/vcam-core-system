<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use RuntimeException;

interface ReplayWebhookDelivery
{
    /**
     * @throws RuntimeException when the delivery does not exist, or is not `dead`
     *                          (Contracts stays framework- and Domain-free — G3c —
     *                          so the concrete exception type is an Infrastructure detail)
     */
    public function replay(ReplayWebhookDeliveryCommand $command): ReplayedWebhookDeliveryView;
}
