<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * ADR-0063 — sends one signed `webhook.test` event to an endpoint, right
 * now, so an integrator can check their receiver (URL reachable, signature
 * verification) before any real event exists. Synchronous, never retried,
 * never persisted as a delivery.
 */
interface TestWebhookEndpoint
{
    public function test(TestWebhookEndpointCommand $command): WebhookTestResultView;
}
