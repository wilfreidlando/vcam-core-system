<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Exception;

use RuntimeException;

/**
 * Raised on the `UNIQUE(endpoint_id, event_id)` race (ADR-0040 §4): the
 * relay is at-least-once, so the same outbox event can be republished
 * twice. The caller treats this as "already fanned out", not an error.
 */
final class WebhookDeliveryAlreadyExists extends RuntimeException
{
    public static function forEndpointAndEvent(string $endpointId, string $eventId): self
    {
        return new self(sprintf(
            'A webhook delivery already exists for endpoint "%s" and event "%s".',
            $endpointId,
            $eventId,
        ));
    }
}
