<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The webhook endpoints registered for one Project, active or not.
 *
 * ADR-0069 §2 — the scope is a REQUIRED parameter, never an optional
 * filter: a console account only ever lists the endpoints of the projects
 * it may see. The command's `projectId` is not nullable, so the listing is
 * always project-scoped; the scope says *which* projects that is.
 */
interface ListWebhookEndpoints
{
    /**
     * @return list<WebhookEndpointView>
     */
    public function list(ListWebhookEndpointsCommand $command, AccessScope $scope): array;
}
