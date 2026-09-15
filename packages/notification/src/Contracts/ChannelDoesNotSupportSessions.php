<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;

/**
 * Thrown when the primary provider resolved for a channel doesn't
 * implement `ChannelSessionProvider` — e.g. an SMS or email adapter has
 * no notion of a linked-device session to manage.
 */
final class ChannelDoesNotSupportSessions extends RuntimeException
{
    public function __construct(
        public readonly ProviderCode $providerCode,
        public readonly string $resolvedClass,
    ) {
        parent::__construct(sprintf(
            'Provider "%s" (%s) does not implement Core\\Notification\\Contracts\\ChannelSessionProvider — it has no linked-device session to manage.',
            $providerCode->toString(),
            $resolvedClass,
        ));
    }
}
