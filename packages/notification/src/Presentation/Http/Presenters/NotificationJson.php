<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Presenters;

use Core\Notification\Contracts\NotificationView;

/**
 * The public JSON shape of a notification, in one place so that
 * `GET /v1/notifications/{id}`, `GET /v1/notifications` and the project
 * export cannot drift apart.
 */
final class NotificationJson
{
    /**
     * @return array<string, mixed>
     */
    public static function status(NotificationView $view): array
    {
        return [
            'id' => $view->id,
            'channel' => $view->channel,
            'status' => $view->status,
            'content_type' => $view->contentType,
            'error_message' => $view->errorMessage,
        ];
    }

    /**
     * The shape of an item of the listing: the status shape, plus the
     * instant the notification was created (the key of the listing's order).
     *
     * @return array<string, mixed>
     */
    public static function listed(NotificationView $view): array
    {
        return self::status($view) + ['created_at' => $view->createdAt];
    }
}
