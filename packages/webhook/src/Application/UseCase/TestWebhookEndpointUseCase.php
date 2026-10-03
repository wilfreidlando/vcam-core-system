<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Webhook\Contracts\TestWebhookEndpoint;
use Core\Webhook\Contracts\TestWebhookEndpointCommand;
use Core\Webhook\Contracts\WebhookTestResultView;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use Core\Webhook\Domain\Port\WebhookSender;
use DateTimeInterface;

/**
 * ADR-0063 — the delivery is built in memory only and sent through the
 * same `WebhookSender` as a real event (same envelope, same signature,
 * same SSRF guard), so a receiver that accepts the test accepts the real
 * thing. Works on an inactive endpoint too: checking a receiver before
 * re-activating it is a legitimate use.
 */
final readonly class TestWebhookEndpointUseCase implements TestWebhookEndpoint
{
    public const EVENT_TYPE = 'webhook.test';

    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private WebhookSender $sender,
        private Identifier $identifier,
        private Clock $clock,
    ) {}

    public function test(TestWebhookEndpointCommand $command): WebhookTestResultView
    {
        $endpoint = $this->endpoints->find($command->endpointId);
        if ($endpoint === null
            || $endpoint->projectId !== $command->projectId
            || ($command->environment !== null && $endpoint->environment !== $command->environment)) {
            throw WebhookEndpointNotFound::withId($command->endpointId);
        }

        $eventId = $this->identifier->generate();
        $delivery = new WebhookDelivery(
            id: $this->identifier->generate(),
            endpointId: $endpoint->id,
            projectId: $endpoint->projectId,
            eventId: $eventId,
            eventType: self::EVENT_TYPE,
            payload: [
                'endpoint_id' => $endpoint->id,
                'environment' => $endpoint->environment->value,
                'message' => 'Test event sent on request. Verify the signature, then answer 2xx.',
                'occurred_at' => $this->clock->now()->format(DateTimeInterface::RFC3339),
            ],
            status: WebhookDeliveryStatus::Pending,
            attempts: 0,
            nextRetryAt: null,
            lastAttemptAt: null,
            lastError: null,
            deliveredAt: null,
        );

        $outcome = $this->sender->send($endpoint, $delivery);

        return new WebhookTestResultView(
            delivered: $outcome->success,
            eventId: $eventId,
            responseStatus: $outcome->httpStatus,
            responseBody: $outcome->responseBody,
            error: $outcome->error,
            durationMs: $outcome->durationMs,
        );
    }
}
