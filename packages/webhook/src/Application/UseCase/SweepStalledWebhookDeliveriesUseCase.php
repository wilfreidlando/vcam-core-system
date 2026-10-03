<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Webhook\Contracts\SweepStalledWebhookDeliveries;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;
use DateInterval;

final readonly class SweepStalledWebhookDeliveriesUseCase implements SweepStalledWebhookDeliveries
{
    public function __construct(
        private WebhookDeliveryRepository $deliveries,
        private Clock $clock,
    ) {}

    public function sweep(int $overdueMinutes, int $limit): array
    {
        $overdueBefore = $this->clock->now()->sub(new DateInterval(sprintf('PT%dM', max(1, $overdueMinutes))));

        return array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->id,
            $this->deliveries->findStalledPending($overdueBefore, $limit),
        );
    }
}
