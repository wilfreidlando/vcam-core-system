<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Export;

use Core\Notification\Contracts\ListNotificationsByCursor;
use Core\Notification\Contracts\ListNotificationsByCursorCommand;
use Core\Notification\Contracts\NotificationExportRecords;
use Core\Notification\Presentation\Http\Presenters\NotificationJson;
use Core\Support\Access\AccessScope;

final readonly class NotificationExportRecordsReader implements NotificationExportRecords
{
    public function __construct(private ListNotificationsByCursor $notifications) {}

    public function page(string $projectId, ?string $cursor, int $limit): array
    {
        $page = $this->notifications->list(
            new ListNotificationsByCursorCommand(projectId: $projectId, cursor: $cursor, limit: $limit),
            AccessScope::forProject($projectId),
        );

        return ['rows' => array_map(NotificationJson::listed(...), $page->items), 'next_cursor' => $page->nextCursor];
    }
}
