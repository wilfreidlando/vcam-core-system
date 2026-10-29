<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\GetNotificationStatus;
use Core\Notification\Contracts\GetNotificationStatusCommand;
use Core\Notification\Domain\Exception\NotificationNotFound;
use Core\Notification\Presentation\Http\Presenters\NotificationJson;
use Core\Notification\Presentation\Http\Responses\NotificationResponses;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class GetNotificationStatusController
{
    public function __construct(
        private readonly GetNotificationStatus $getNotificationStatus,
    ) {}

    /**
     * Get a notification's current status.
     *
     * Requires the `notifications:read` scope, and returns the same
     * not-found response whether the notification doesn't exist or
     * belongs to another project.
     *
     * @throws NotificationNotFound the notification doesn't exist, or belongs to another project (404)
     */
    #[ScrambleResponse(status: 200, type: NotificationResponses::STATUS, description: 'The current status of the notification.')]
    public function __invoke(string $notificationId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notifications:read')) {
            return ProblemDetails::forbidden('The notifications:read scope is required.');
        }

        $view = $this->getNotificationStatus->get(new GetNotificationStatusCommand($notificationId, $project->projectId));

        return new JsonResponse(NotificationJson::status($view));
    }
}
