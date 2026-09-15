<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Support;

use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\QueueWebhookDelivery;
use Core\Webhook\Presentation\Jobs\DeliverWebhookJob;

final class QueuedWebhookDeliveryDispatcher implements QueueWebhookDelivery
{
    public function queue(DeliverWebhookCommand $command): void
    {
        DeliverWebhookJob::dispatch($command);
    }
}
