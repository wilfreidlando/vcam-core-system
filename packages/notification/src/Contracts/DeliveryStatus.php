<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Notification\NotificationStatus;

/**
 * Return type of `NotificationChannelProvider::getStatus()` and
 * `::handleDeliveryCallback()` — the canonical status an adapter has
 * already translated a raw provider status into (spec §9.4).
 */
final readonly class DeliveryStatus
{
    public function __construct(
        public NotificationStatus $status,
        public ?string $reason = null,
    ) {}
}
