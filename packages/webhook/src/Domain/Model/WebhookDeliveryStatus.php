<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

/**
 * Canonical lifecycle of one delivery attempt sequence for one
 * (endpoint, event) pair. There is no "delivering" state: an attempt is
 * either in flight (tracked by Horizon, not by this column) or it has
 * already produced an outcome.
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Dead = 'dead';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
