<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Webhook\Contracts\FailWebhookDelivery;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use Psr\Log\LoggerInterface;

final readonly class FailWebhookDeliveryUseCase implements FailWebhookDelivery
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function fail(string $deliveryId, string $reason): void
    {
        $delivery = $this->deliveries->find($deliveryId);
        if ($delivery === null || $delivery->status->isTerminal()) {
            return;
        }

        $this->deliveries->save($delivery->markDead($this->clock->now(), $reason));

        $this->logger->error('webhook.delivery.job_failed', [
            'delivery_id' => $delivery->id,
            'endpoint_id' => $delivery->endpointId,
            'reason' => $reason,
        ]);
    }
}
