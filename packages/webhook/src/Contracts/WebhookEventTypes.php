<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * The closed set of event types the Core actually emits through the outbox
 * — the single list used to validate subscriptions and to document them
 * (ADR-0063: the API used to accept `notification.status_changed`, never
 * emitted, and to refuse `notification.delivered`/`notification.read`,
 * which are). `webhook.test` is not subscribable: it is only sent on
 * request, by `POST /v1/webhook-endpoints/{id}/test`.
 */
final class WebhookEventTypes
{
    public const ALL = [
        'payment.status_changed',
        'notification.delivered',
        'notification.read',
        'notification.failed',
        'wallet.credited',
        'wallet.debited',
    ];
}
