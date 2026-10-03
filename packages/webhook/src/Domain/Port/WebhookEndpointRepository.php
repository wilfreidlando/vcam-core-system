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
    public function findActiveForProject(string $projectId, ?\Core\Support\Environment $environment = null): array;

    /**
     * Every endpoint registered for a Project, active or not — the admin
     * console's visibility need. `findActiveForProject()` stays
     * fan-out-only (who should actually receive the next event); an
     * operator managing endpoints needs to see a deactivated one too, to
     * reactivate or edit it.
     *
     * @return list<WebhookEndpoint>
     */
    public function findAllForProject(string $projectId, ?\Core\Support\Environment $environment = null): array;
}
