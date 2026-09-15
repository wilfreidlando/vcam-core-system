<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * The raw envelope a provider's inbound status callback carries — built
 * by that provider's own webhook controller (none exists yet, ADR-0041
 * §6) and handed to `NotificationChannelProvider::handleDeliveryCallback()`,
 * which alone knows how to parse it into a canonical `DeliveryStatus`.
 */
final readonly class CallbackPayload
{
    /**
     * @param array<string, mixed> $raw
     * @param array<string, string> $headers
     */
    public function __construct(
        public array $raw,
        public array $headers = [],
    ) {}
}
