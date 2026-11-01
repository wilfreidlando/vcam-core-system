<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Admin\Presentation\Http\Controllers\Concerns\AuthorizesInScope;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ListDeadWebhookDeliveriesController
{
    use AuthorizesInScope;

    public function __construct(
        private readonly ListDeadWebhookDeliveries $listDeadWebhookDeliveries,
    ) {}

    /**
     * List dead (DLQ) webhook deliveries.
     *
     * Optionally scoped to a `project_id`. Requires `webhooks:read`.
     *
     * ADR-0069 §2 — the account's scope always applies on top of the
     * requested project: a `project_id` outside the scope yields an empty
     * page, and the unfiltered listing only shows the account's projects.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        $scope = $this->permittedScope($operator, 'webhooks:read');
        if ($scope === null) {
            return ProblemDetails::forbidden('The webhooks:read permission is required.');
        }

        $projectIdRaw = $request->query('project_id');
        $projectId = is_string($projectIdRaw) && $projectIdRaw !== '' ? $projectIdRaw : null;

        if ($projectId !== null) {
            Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();
        }

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listDeadWebhookDeliveries->list(
            new ListDeadWebhookDeliveriesCommand($projectId, $page, $perPage),
            // ADR-0069 §2 — the scope is a required parameter of the
            // port: the dead queue never crosses the projects the
            // console account may see.
            $scope,
        );

        return new JsonResponse([
            'data' => array_map(static fn ($delivery): array => [
                'id' => $delivery->id,
                'endpoint_id' => $delivery->endpointId,
                'project_id' => $delivery->projectId,
                'event_id' => $delivery->eventId,
                'event_type' => $delivery->eventType,
                'payload' => $delivery->payload,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->lastError,
                'last_response_status' => $delivery->lastResponseStatus,
                'last_response_body' => $delivery->lastResponseBody,
                'last_attempt_at' => $delivery->lastAttemptAt?->format(DATE_ATOM),
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
