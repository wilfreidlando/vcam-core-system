<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Exception;

use RuntimeException;

/**
 * Replay (ADR-0009, ADR-0040 §7) is for exhausted deliveries only — a
 * `pending` delivery already has a retry scheduled, and a `delivered` one
 * needs no replay.
 */
final class WebhookDeliveryNotDead extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Webhook delivery "%s" is not dead; only a dead delivery can be replayed.', $id));
    }
}
