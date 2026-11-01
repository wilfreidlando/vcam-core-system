<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\ListNotificationsByCursor;
use Core\Notification\Contracts\ListNotificationsByCursorCommand;
use Core\Notification\Presentation\Http\Presenters\NotificationJson;
use Core\Notification\Presentation\Http\Requests\ListNotificationsRequest;
use Core\Notification\Presentation\Http\Responses\NotificationResponses;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/notifications?status=&channel=&cursor=&limit=` — ADR-0088 §2,
 * the cursor-paginated listing of a project's notifications.
 */
final class ListNotificationsController
{
    public function __construct(
        private readonly ListNotificationsByCursor $listNotifications,
    ) {}

    /**
     * List the calling Project's notifications.
     *
     * The listing is paginated by an opaque cursor: pass `next_cursor`
     * back as `cursor` to read the next page, and `null` when there is
     * no more. Notifications come oldest first, in a stable order, so
     * reading the whole listing while new notifications are sent neither
     * repeats nor skips one created before you started. `created_from`
     * is inclusive and `created_to` exclusive. Each item has the shape
     * of `GET /v1/notifications/{id}`, plus its `created_at`; the
     * content and the recipient are never listed.
     *
     * Requires the `notifications:read` scope.
     */
    #[ScrambleResponse(status: 200, type: NotificationResponses::NOTIFICATION_PAGE, description: 'A page of notifications, and the cursor of the next one.')]
    public function __invoke(ListNotificationsRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notifications:read')) {
            return ProblemDetails::forbidden('The notifications:read scope is required.');
        }

        $status = $request->query('status');
        $channel = $request->query('channel');
        $cursor = $request->query('cursor');
        $from = $request->query('created_from');
        $to = $request->query('created_to');

        try {
            $page = $this->listNotifications->list(
                new ListNotificationsByCursorCommand(
                    projectId: $project->projectId,
                    status: is_string($status) && $status !== '' ? $status : null,
                    channel: is_string($channel) && $channel !== '' ? $channel : null,
                    createdFrom: is_string($from) && $from !== '' ? new DateTimeImmutable($from) : null,
                    createdTo: is_string($to) && $to !== '' ? new DateTimeImmutable($to) : null,
                    cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
                    limit: $request->integer('limit', 50),
                ),
                // ADR-0069 §2 — the public API plane's scope is the calling
                // project itself: the listing never crosses the projects.
                AccessScope::forProject($project->projectId),
            );
        } catch (InvalidArgumentException) {
            return ProblemDetails::make('validation-failed', 'The request payload is invalid', 422, 'The cursor is not one this listing issued.');
        }

        return new JsonResponse([
            'data' => array_map(NotificationJson::listed(...), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
