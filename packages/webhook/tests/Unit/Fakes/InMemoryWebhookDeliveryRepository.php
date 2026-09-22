<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Webhook\Domain\Exception\WebhookDeliveryAlreadyExists;
use Core\Webhook\Domain\Model\WebhookDelivery;
use Core\Webhook\Domain\Model\WebhookDeliveryStatus;
use Core\Webhook\Domain\Port\WebhookDeliveryRepository;

final class InMemoryWebhookDeliveryRepository implements WebhookDeliveryRepository
{
    /** @var array<string, WebhookDelivery> */
    private array $deliveries = [];

    public function create(WebhookDelivery $delivery): void
    {
        if ($delivery->replayedFromId === null) {
            foreach ($this->deliveries as $existing) {
                if ($existing->replayedFromId === null
                    && $existing->endpointId === $delivery->endpointId
                    && $existing->eventId === $delivery->eventId
                ) {
                    throw WebhookDeliveryAlreadyExists::forEndpointAndEvent($delivery->endpointId, $delivery->eventId);
                }
            }
        }

        $this->deliveries[$delivery->id] = $delivery;
    }

    public function save(WebhookDelivery $delivery): void
    {
        $this->deliveries[$delivery->id] = $delivery;
    }

    public function find(string $id): ?WebhookDelivery
    {
        return $this->deliveries[$id] ?? null;
    }

    public function count(): int
    {
        return count($this->deliveries);
    }

    public function findByStatusPaginated(?WebhookDeliveryStatus $status, ?string $projectId, int $page, int $perPage): array
    {
        $matching = array_values(array_filter(
            $this->deliveries,
            static fn (WebhookDelivery $delivery): bool => ($status === null || $delivery->status === $status)
                && ($projectId === null || $delivery->projectId === $projectId),
        ));

        $total = count($matching);
        $offset = ($page - 1) * $perPage;

        return ['items' => array_slice($matching, $offset, $perPage), 'total' => $total];
    }
}
