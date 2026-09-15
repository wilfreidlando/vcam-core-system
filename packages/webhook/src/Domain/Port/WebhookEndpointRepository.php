<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Port;

use Core\Webhook\Domain\Model\WebhookEndpoint;

interface WebhookEndpointRepository
{
    public function create(WebhookEndpoint $endpoint): void;

    public function save(WebhookEndpoint $endpoint): void;

    public function find(string $id): ?WebhookEndpoint;

    /**
     * @return list<WebhookEndpoint>
     */
    public function findActiveForProject(string $projectId): array;
}
