<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Presentation\Http\Requests\RegisterWebhookEndpointRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/webhook-endpoints` — self-service registration (ADR-0040 §6),
 * unlike API key issuance which stays CLI-only. `secret` is returned in
 * this response only — never again.
 */
final class RegisterWebhookEndpointController
{
    public function __construct(
        private readonly RegisterWebhookEndpoint $registerWebhookEndpoint,
    ) {}

    /**
     * Register a webhook endpoint.
     *
     * The returned `secret` signs every delivery to this endpoint
     * (`X-Signature`/`X-Timestamp` headers, HMAC-SHA256) and is shown
     * exactly once — store it, it cannot be retrieved again. Requires the
     * `webhooks:write` scope.
     */
    public function __invoke(RegisterWebhookEndpointRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('webhooks:write')) {
            return ProblemDetails::forbidden('The webhooks:write scope is required.');
        }

        /** @var array{url: string, events: list<string>} $data */
        $data = $request->validated();

        $view = $this->registerWebhookEndpoint->register(new RegisterWebhookEndpointCommand(
            projectId: $project->projectId,
            url: $data['url'],
            events: $data['events'],
            environment: $project->environment,
        ));

        return new JsonResponse([
            'id' => $view->id,
            'url' => $view->url,
            'events' => $view->events,
            'secret' => $view->secret,
        ], 201);
    }
}
