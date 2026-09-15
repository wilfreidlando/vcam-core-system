<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Webhook\Contracts\DeliverWebhook;
use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Contracts\WebhookDeliveryOutcome;
use Core\Webhook\Contracts\WebhookDeliveryOutcomeStatus;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookRetryPolicy;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use Core\Webhook\Domain\Port\WebhookSender;
use Psr\Log\LoggerInterface;

/**
 * One delivery attempt, called from `Presentation\Jobs\DeliverWebhookJob`.
 * Never sleeps and never schedules anything itself — it returns the
 * outcome (delivered / retry-in-N-seconds / dead) and lets the Job, which
 * owns the Horizon queue concern, call `release()` (ADR-0040 §3).
 */
final readonly class DeliverWebhookUseCase implements DeliverWebhook
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private WebhookEndpointRepository $endpoints,
        private WebhookSender $sender,
        private WebhookRetryPolicy $retryPolicy,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function deliver(DeliverWebhookCommand $command): WebhookDeliveryOutcome
    {
        $delivery = $this->deliveries->find($command->deliveryId);
        if ($delivery === null) {
            throw WebhookDeliveryNotFound::withId($command->deliveryId);
        }

        if ($delivery->status->isTerminal()) {
            // Already delivered or dead — a duplicate job execution
            // (Horizon at-least-once) is a no-op, not an error.
            return new WebhookDeliveryOutcome(
                status: $delivery->status->value === 'delivered'
                    ? WebhookDeliveryOutcomeStatus::Delivered
                    : WebhookDeliveryOutcomeStatus::Dead,
                retryAfterSeconds: null,
            );
        }

        $endpoint = $this->endpoints->find($delivery->endpointId);
        if ($endpoint === null) {
            throw WebhookEndpointNotFound::withId($delivery->endpointId);
        }

        $now = $this->clock->now();
        $outcome = $this->sender->send($endpoint, $delivery);

        if ($outcome->success) {
            $this->deliveries->save($delivery->recordSuccess($now));

            return new WebhookDeliveryOutcome(WebhookDeliveryOutcomeStatus::Delivered, null);
        }

        $attemptNumber = $delivery->attempts + 1;
        $nextRetryAt = $this->retryPolicy->nextRetryAt($attemptNumber, $now);

        $this->deliveries->save($delivery->recordFailure(
            $outcome->error ?? 'unknown error',
            $now,
            $nextRetryAt,
        ));

        if ($nextRetryAt === null) {
            $this->logger->warning('webhook.delivery.dead', [
                'delivery_id' => $delivery->id,
                'endpoint_id' => $delivery->endpointId,
                'attempts' => $attemptNumber,
                'last_error' => $outcome->error,
            ]);

            return new WebhookDeliveryOutcome(WebhookDeliveryOutcomeStatus::Dead, null);
        }

        $retryAfterSeconds = max(1, $nextRetryAt->getTimestamp() - $now->getTimestamp());

        $this->logger->info('webhook.delivery.retry_scheduled', [
            'delivery_id' => $delivery->id,
            'endpoint_id' => $delivery->endpointId,
            'attempts' => $attemptNumber,
            'retry_after_seconds' => $retryAfterSeconds,
        ]);

        return new WebhookDeliveryOutcome(WebhookDeliveryOutcomeStatus::RetryScheduled, $retryAfterSeconds);
    }
}
