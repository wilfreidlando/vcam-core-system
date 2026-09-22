<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Model\WebhookSendOutcome;
use Core\Webhook\Domain\Port\WebhookSender;

final class FakeWebhookSender implements WebhookSender
{
    public WebhookSendOutcome $nextOutcome;

    /** @var list<WebhookDelivery> */
    public array $sent = [];

    public function __construct()
    {
        $this->nextOutcome = WebhookSendOutcome::success(200, null);
    }

    public function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery): WebhookSendOutcome
    {
        $this->sent[] = $delivery;

        return $this->nextOutcome;
    }
}
