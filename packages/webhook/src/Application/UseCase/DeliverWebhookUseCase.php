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
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
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
    private const DUE_TIME_TOLERANCE_SECONDS = 5;

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
                status: match ($delivery->status) {
                    WebhookDeliveryStatus::Delivered => WebhookDeliveryOutcomeStatus::Delivered,
                    WebhookDeliveryStatus::Canceled => WebhookDeliveryOutcomeStatus::Canceled,
                    default => WebhookDeliveryOutcomeStatus::Dead,
                },
                retryAfterSeconds: null,
            );
        }

        $now = $this->clock->now();

        // A job running well before the delivery's own due time is a
        // superseded duplicate (a `webhook:sweep` re-dispatch raced the
        // original job): the chain that set `next_retry_at` owns the next
        // attempt, so this execution does nothing.
        if ($delivery->nextRetryAt !== null && $now->getTimestamp() < $delivery->nextRetryAt->getTimestamp() - self::DUE_TIME_TOLERANCE_SECONDS) {
            return new WebhookDeliveryOutcome(WebhookDeliveryOutcomeStatus::RetryScheduled, null);
        }

        $endpoint = $this->endpoints->find($delivery->endpointId);
        if ($endpoint === null) {
            throw WebhookEndpointNotFound::withId($delivery->endpointId);
        }

        if (! $endpoint->active) {
            // Audit 2026-10-03 §4.4: a deactivated endpoint receives nothing
            // more — not even the retries already scheduled before it was
            // deactivated.
            $this->deliveries->save($delivery->cancel($now, 'Endpoint deactivated before delivery.'));
            $this->logger->info('webhook.delivery.canceled', [
                'delivery_id' => $delivery->id,
                'endpoint_id' => $delivery->endpointId,
            ]);

            return new WebhookDeliveryOutcome(WebhookDeliveryOutcomeStatus::Canceled, null);
        }

        $outcome = $this->sender->send($endpoint, $delivery);

        if ($outcome->success) {
            $this->deliveries->save($delivery->recordSuccess($now, $outcome->httpStatus, $outcome->responseBody));

            return new WebhookDeliveryOutcome(WebhookDeliveryOutcomeStatus::Delivered, null);
        }

        $attemptNumber = $delivery->attempts + 1;
        $nextRetryAt = $this->retryPolicy->nextRetryAt($attemptNumber, $now, $outcome->isPermanentFailure);

        $this->deliveries->save($delivery->recordFailure(
            $outcome->error ?? 'unknown error',
            $now,
            $nextRetryAt,
            $outcome->httpStatus,
            $outcome->responseBody,
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
