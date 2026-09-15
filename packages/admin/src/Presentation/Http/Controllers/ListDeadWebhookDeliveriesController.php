<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\ListDeadWebhookDeliveries;
use Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ListDeadWebhookDeliveriesController
{
    public function __construct(
        private readonly ListDeadWebhookDeliveries $listDeadWebhookDeliveries,
    ) {}

    /**
     * List dead (DLQ) webhook deliveries.
     *
     * Optionally scoped to a `project_id`. Requires `webhooks:read`.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('webhooks:read')) {
            return ProblemDetails::forbidden('The webhooks:read permission is required.');
        }

        $projectIdRaw = $request->query('project_id');
        $projectId = is_string($projectIdRaw) && $projectIdRaw !== '' ? $projectIdRaw : null;

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listDeadWebhookDeliveries->list(
            new ListDeadWebhookDeliveriesCommand($projectId, $page, $perPage),
        );

        return new JsonResponse([
            'data' => array_map(static fn ($delivery): array => [
                'id' => $delivery->id,
                'endpoint_id' => $delivery->endpointId,
                'project_id' => $delivery->projectId,
                'event_type' => $delivery->eventType,
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->lastError,
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
