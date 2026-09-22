<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\DeactivateWebhookEndpoint;
use Core\Webhook\Contracts\DeactivateWebhookEndpointCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class DeactivateWebhookEndpointController
{
    public function __construct(
        private readonly DeactivateWebhookEndpoint $deactivateWebhookEndpoint,
    ) {}

    /**
     * Deactivate a webhook endpoint.
     *
     * Stops future deliveries; already-scheduled retries for deliveries
     * created before deactivation still run to completion or DLQ.
     * Requires the `webhooks:write` scope.
     *
     * @throws WebhookEndpointNotFound the endpoint doesn't exist, or belongs to another project (404)
     */
    public function __invoke(string $endpointId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('webhooks:write')) {
            return ProblemDetails::forbidden('The webhooks:write scope is required.');
        }

        $this->deactivateWebhookEndpoint->deactivate(new DeactivateWebhookEndpointCommand(
            projectId: $project->projectId,
            endpointId: $endpointId,
        ));

        return new JsonResponse(status: 204);
    }
}
