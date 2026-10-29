<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Responses;

/**
 * The documented shape of the notification listing (ADR-0088 §2) — it
 * follows `NotificationJson::listed()` key for key.
 */
final class NotificationResponses
{
    public const STATUS = 'array{'
        .'id: string, '
        .'channel: string, '
        .'status: string, '
        .'content_type: string, '
        .'error_message: string|null'
        .'}';

    public const NOTIFICATION = 'array{'
        .'id: string, '
        .'channel: string, '
        .'status: string, '
        .'content_type: string, '
        .'error_message: string|null, '
        .'created_at: string|null'
        .'}';

    public const NOTIFICATION_PAGE = 'array{'
        .'data: list<'.self::NOTIFICATION.'>, '
        .'next_cursor: string|null'
        .'}';
}
