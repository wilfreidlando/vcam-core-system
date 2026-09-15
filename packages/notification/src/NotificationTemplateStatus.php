<?php

declare(strict_types=1);

namespace Core\Notification;

/**
 * Whether a template has cleared the provider's approval process (WhatsApp
 * template messages require this before they can be sent). A `pending`
 * template exists for visibility/preparation but is not eligible for
 * `SendNotificationUseCase` to select.
 */
enum NotificationTemplateStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
}
