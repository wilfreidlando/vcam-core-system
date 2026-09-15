<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Provider\Waha;

use Core\Notification\Contracts\NotificationStatusMapper;
use Core\Notification\NotificationStatus;
use Core\Support\Integration\Exception\UnknownProviderStatusException;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Maps a WAHA `message.ack` numeric code (stringified) to the canonical
 * `NotificationStatus`. `-1` (error) is the only definitive failure;
 * `0`/`1` both mean "accepted, not yet delivered" so both collapse to
 * `Sent`.
 */
final readonly class WahaStatusMapper implements NotificationStatusMapper
{
    public function __construct(
        private ProviderCode $providerCode,
    ) {}

    public function map(string $raw): NotificationStatus
    {
        return match ($raw) {
            RawWahaStatus::ERROR => NotificationStatus::Failed,
            RawWahaStatus::PENDING, RawWahaStatus::SENT => NotificationStatus::Sent,
            RawWahaStatus::DELIVERED => NotificationStatus::Delivered,
            RawWahaStatus::READ, RawWahaStatus::PLAYED => NotificationStatus::Read,
            default => throw UnknownProviderStatusException::forValue($this->providerCode, $raw),
        };
    }
}
