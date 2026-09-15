<?php

declare(strict_types=1);

namespace Core\Notification;

/**
 * WhatsApp's own distinction (spec §9.5 §1): a `template` message may be
 * sent outside the 24h customer-session window but needs prior approval;
 * a `session` message is free-form but only valid inside that window.
 * Stored so a future adapter can decide which to send — the Core does
 * not hardcode this channel-specific rule itself.
 */
enum NotificationTemplateKind: string
{
    case Template = 'template';
    case Session = 'session';
}
