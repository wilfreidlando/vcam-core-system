<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\OperatorContext;
use Core\Notification\Contracts\ListNotifications;
use Core\Notification\Contracts\ListNotificationsCommand;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ListProjectNotificationsController
{
    public function __construct(
        private readonly ListNotifications $listNotifications,
    ) {}

    /**
     * List a Project's notifications.
     *
     * Optionally filtered by canonical `status`. Requires `notifications:read`.
     */
    public function __invoke(Request $request, string $projectId, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('notifications:read')) {
            return ProblemDetails::forbidden('The notifications:read permission is required.');
        }

        Validator::make(['projectId' => $projectId], ['projectId' => 'uuid'])->validate();

        $statusRaw = $request->query('status');
        $status = is_string($statusRaw) && $statusRaw !== '' ? $statusRaw : null;

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listNotifications->list(new ListNotificationsCommand($projectId, $status, $page, $perPage));

        return new JsonResponse([
            'data' => array_map(static fn ($notification): array => [
                'id' => $notification->id,
                'channel' => $notification->channel,
                'status' => $notification->status,
                'content_type' => $notification->contentType,
                'error_message' => $notification->errorMessage,
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
