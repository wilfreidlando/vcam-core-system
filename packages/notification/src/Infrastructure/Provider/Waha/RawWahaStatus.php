<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Provider\Waha;

/**
 * Raw WhatsApp Web `ack` codes carried by WAHA's `message.ack` webhook
 * event — a stable part of the underlying WhatsApp Web protocol (shared
 * across the whatsapp-web.js/Baileys engines WAHA is built on), not a
 * WAHA-specific vocabulary. G6 — these values MUST NOT appear outside
 * this file or `WahaStatusMapper`.
 *
 * Kept as strings (not ints) because `NotificationStatusMapper::map()`
 * is typed `string $raw`, matching every other status mapper in the
 * codebase.
 */
final class RawWahaStatus
{
    public const ERROR = '-1';

    public const PENDING = '0';

    public const SENT = '1';

    public const DELIVERED = '2';

    public const READ = '3';

    public const PLAYED = '4';

    private function __construct() {}
}
