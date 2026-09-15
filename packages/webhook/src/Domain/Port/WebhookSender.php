<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Port;

use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Model\WebhookSendOutcome;

/**
 * One HTTP attempt at delivering `$delivery` to `$endpoint`. Never throws
 * for an ordinary transport/HTTP-level failure — that is a
 * `WebhookSendOutcome::failure()`, not an exception, so
 * `DeliverWebhookUseCase` can decide retry vs DLQ without a catch block
 * per failure mode.
 */
interface WebhookSender
{
    public function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): WebhookSendOutcome;
}
