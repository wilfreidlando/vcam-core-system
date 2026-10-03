<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\WebhookEndpointView;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ListWebhookEndpointsController
{
    public function __construct(
        private readonly ListWebhookEndpoints $listWebhookEndpoints,
    ) {}

    /**
     * List the calling Project's webhook endpoints.
     *
     * Never includes the signing secret — it is shown once, at
     * registration, and never again. Requires the `webhooks:read` scope.
     */
    public function __invoke(ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('webhooks:read')) {
            return ProblemDetails::forbidden('The webhooks:read scope is required.');
        }

        /** @var list<WebhookEndpointView> $endpoints */
        $endpoints = $this->listWebhookEndpoints->list(new ListWebhookEndpointsCommand($project->projectId, $project->environment));

        return new JsonResponse(['data' => $endpoints]);
    }
}
