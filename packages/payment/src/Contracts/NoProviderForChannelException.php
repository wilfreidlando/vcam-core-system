<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

/**
 * No provider (project override or platform default) is configured for
 * a channel — a configuration gap, not a customer-facing error: it means
 * an operator forgot to route a channel, not that the channel itself is
 * invalid.
 */
final class NoProviderForChannelException extends RuntimeException
{
    public static function forChannel(PaymentChannel $channel, string $projectId): self
    {
        return new self("No provider is configured for channel '{$channel}' (project {$projectId}).");
    }
}
