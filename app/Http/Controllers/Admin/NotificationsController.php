<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Notification\Contracts\GetNotificationDetails;
use Core\Notification\Contracts\ListNotifications;
use Core\Notification\Contracts\ListNotificationsCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0062 — a Project's notifications in the console: list (filterable by
 * status and environment) and a detail page carrying everything the Core
 * knows about one notification — the content sent, its variables (the
 * recipient's phone number included, visible to authorized operators per
 * D5), and every provider attempt of the fallback cascade.
 */
final class NotificationsController
{
    private const STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed'];

    public function __construct(
        private readonly ListNotifications $listNotifications,
        private readonly GetNotificationDetails $details,
        private readonly GetProject $getProject,
    ) {}

    public function index(string $projectId, Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('notifications:read')) {
            abort(403, 'La permission notifications:read est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $status = (string) $request->query('status', '');
        if (! in_array($status, self::STATUSES, true)) {
            $status = '';
        }
        $environment = (string) $request->query('environment', '');
        if (! in_array($environment, ['live', 'sandbox'], true)) {
            $environment = '';
        }

        $result = $this->listNotifications->list(new ListNotificationsCommand(
            projectId: $projectId,
            status: $status === '' ? null : $status,
            page: $page,
            perPage: $perPage,
            environment: $environment === '' ? null : $environment,
        ));

        return response()->view('admin.notifications.index', [
            'title' => 'Notifications',
            'project' => $project,
            'notifications' => $result->items,
            'total' => $result->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['status' => $status, 'environment' => $environment],
            'statuses' => self::STATUSES,
        ]);
    }

    public function show(string $projectId, string $notificationId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('notifications:read')) {
            abort(403, 'La permission notifications:read est requise.');
        }

        Validator::make(
            ['project_id' => $projectId, 'notification_id' => $notificationId],
            ['project_id' => 'uuid', 'notification_id' => 'uuid'],
        )->validate();

        $notification = $this->details->get($notificationId);
        // Scoped to the Project in the URL: a notification of another
        // Project is "not found" here, never shown under the wrong name.
        if ($notification === null || $notification->projectId !== $projectId) {
            abort(404, 'Notification introuvable pour ce projet.');
        }

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        return response()->view('admin.notifications.show', [
            'title' => 'Notification '.substr($notification->id, 0, 8).'…',
            'project' => $project,
            'notification' => $notification,
        ]);
    }
}
