<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Webhook\Domain\Model\WebhookDeliveryAttempt;
use Core\Webhook\Domain\Port\WebhookDeliveryAttemptRepository;

final class InMemoryWebhookDeliveryAttemptRepository implements WebhookDeliveryAttemptRepository
{
    /** @var list<WebhookDeliveryAttempt> */
    public array $attempts = [];

    public function record(WebhookDeliveryAttempt $attempt): void
    {
        $this->attempts[] = $attempt;
    }

    public function forDeliveries(array $deliveryIds): array
    {
        $grouped = [];
        foreach ($this->attempts as $attempt) {
            if (in_array($attempt->deliveryId, $deliveryIds, true)) {
                $grouped[$attempt->deliveryId][] = $attempt;
            }
        }

        return $grouped;
    }
}
