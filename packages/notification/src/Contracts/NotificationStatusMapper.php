<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Notification\NotificationStatus;

/**
 * Port that translates a raw provider status/ack value into the
 * canonical `NotificationStatus` enum — mirrors
 * `Core\Payment\Contracts\PaymentStatusMapper`, narrowing the generic
 * `Core\Support\Integration\Mapping\StatusMapper` so callers don't have
 * to assert the return type at every call site.
 *
 * Adapter implementations live in `Core\Notification\Infrastructure\Provider\*`
 * and MUST be the only place where raw provider status strings/codes
 * appear (G6).
 */
interface NotificationStatusMapper
{
    /**
     * @throws \Core\Support\Integration\Exception\UnknownProviderStatusException
     *                                                                            when the raw value is not part of the adapter's vocabulary.
     */
    public function map(string $raw): NotificationStatus;
}
