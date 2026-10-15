<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\TestWebhookEndpoint;
use Core\Webhook\Contracts\TestWebhookEndpointCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class TestWebhookEndpointController
{
    public function __construct(
        private readonly TestWebhookEndpoint $testWebhookEndpoint,
    ) {}

    /**
     * Send a test event to a webhook endpoint.
     *
     * Sends one signed `webhook.test` event to the endpoint, right now, with
     * the same envelope, headers and signature as a real event — use it to
     * check that your receiver is reachable and verifies the signature.
     * Never retried, never counted as a delivery. Requires the
     * `webhooks:write` scope.
     *
     * `delivered` is true when your endpoint answered 2xx; otherwise
     * `response_status` / `error` say why.
     *
     * @response array{delivered: bool, event_id: string, response_status: int|null, response_body: string|null, error: string|null, duration_ms: int|null}
     *
     * @throws WebhookEndpointNotFound the endpoint doesn't exist, or belongs to another project (404)
     */
    public function __invoke(string $endpointId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('webhooks:write')) {
            return ProblemDetails::forbidden('The webhooks:write scope is required.');
        }

        $result = $this->testWebhookEndpoint->test(new TestWebhookEndpointCommand(
            projectId: $project->projectId,
            endpointId: $endpointId,
        ));

        return new JsonResponse([
            'delivered' => $result->delivered,
            'event_id' => $result->eventId,
            'response_status' => $result->responseStatus,
            'response_body' => $result->responseBody,
            'error' => $result->error,
            'duration_ms' => $result->durationMs,
        ]);
    }
}
