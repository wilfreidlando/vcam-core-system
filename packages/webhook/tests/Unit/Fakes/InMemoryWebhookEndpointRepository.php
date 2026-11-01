<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use DateTimeImmutable;

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

    public function countActiveByProject(string $projectId): int
    {
        return count(array_filter(
            $this->endpoints,
            static fn (WebhookEndpoint $endpoint): bool => $endpoint->projectId === $projectId && $endpoint->active,
        ));
    }

    public function lockProject(string $projectId): void
    {
        // No real lock here: the in-memory collection is single-threaded,
        // so there is nothing to serialise. The lock itself is proven
        // against PostgreSQL, in
        // packages/webhook/tests/Integration/Application/WebhookEndpointCapConcurrencyTest.php.
    }

    public function findForUpdate(string $id): ?WebhookEndpoint
    {
        return $this->find($id);
    }

    public function purgeExpiredPreviousSecrets(DateTimeImmutable $now): int
    {
        $purged = 0;
        foreach ($this->endpoints as $id => $endpoint) {
            if ($endpoint->previousSecret !== null && $endpoint->previousSecretAt($now) === null) {
                $this->endpoints[$id] = $endpoint->withoutPreviousSecret();
                $purged++;
            }
        }

        return $purged;
    }
}
