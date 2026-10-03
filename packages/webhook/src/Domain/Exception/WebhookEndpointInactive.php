<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Exception;

use RuntimeException;

/**
 * Audit 2026-10-03 §4.4 — nothing is sent to a deactivated endpoint, not
 * even a replay: an integrator who deactivates an endpoint (possibly
 * because its secret leaked) must stop receiving traffic immediately.
 */
final class WebhookEndpointInactive extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Webhook endpoint "%s" is deactivated; reactivate it before replaying.', $id));
    }
}
