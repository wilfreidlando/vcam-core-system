<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final class InMemoryWebhookEndpointRepository implements WebhookEndpointRepository
{
    /** @var array<string, WebhookEndpoint> */
    private array $endpoints = [];

    public function create(WebhookEndpoint $endpoint): void
    {
        $this->endpoints[$endpoint->id] = $endpoint;
    }

    public function save(WebhookEndpoint $endpoint): void
    {
        $this->endpoints[$endpoint->id] = $endpoint;
    }

    public function find(string $id): ?WebhookEndpoint
    {
        return $this->endpoints[$id] ?? null;
    }

    public function findActiveForProject(string $projectId): array
    {
        return array_values(array_filter(
            $this->endpoints,
            static fn (WebhookEndpoint $endpoint): bool => $endpoint->projectId === $projectId && $endpoint->active,
        ));
    }

    public function findAllForProject(string $projectId): array
    {
        return array_values(array_filter(
            $this->endpoints,
            static fn (WebhookEndpoint $endpoint): bool => $endpoint->projectId === $projectId,
        ));
    }
}
