<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * Queues one delivery attempt (`Core\Webhook\Presentation\Jobs\DeliverWebhookJob`
 * behind the scenes). A published port because `Presentation/` is never
 * reachable cross-module (G3c) — `admin`'s replay controller needs to queue
 * a delivery after calling `ReplayWebhookDelivery`, and only this module's
 * own `Presentation/` may dispatch its own Job.
 */
interface QueueWebhookDelivery
{
    public function queue(DeliverWebhookCommand $command): void;
}
