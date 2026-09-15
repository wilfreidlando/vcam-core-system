<?php

declare(strict_types=1);

namespace Core\Notification\Contracts\Events;

use Core\Notification\NotificationStatus;

/**
 * Provider-reported status change for a notification the Core sent —
 * mirrors `Core\Payment\Contracts\Events\PaymentStatusChanged`.
 *
 * Built by a future adapter's own webhook controller (or its
 * `getStatus()` poll), and handed directly to
 * `NotificationStatusChangeHandler::handle()` — never round-tripped
 * through the outbox itself, unlike the canonical events (`notification.delivered`,
 * `.read`, `.failed`) the handler publishes as a *result* of this change.
 */
final readonly class NotificationStatusChanged
{
    public function __construct(
        public string $providerCode,
        public string $providerMessageId,
        public NotificationStatus $status,
        public ?string $reason = null,
    ) {}
}
