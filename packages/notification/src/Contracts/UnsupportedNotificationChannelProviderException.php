<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;

/**
 * Thrown by `NotificationChannelProviderRegistry::gatewayFor()` when the
 * underlying generic registry returns a gateway that does not implement
 * `NotificationChannelProvider` — mirrors
 * `Core\Payment\Contracts\UnsupportedPaymentGatewayException`.
 */
final class UnsupportedNotificationChannelProviderException extends RuntimeException
{
    public function __construct(
        public readonly ProviderCode $providerCode,
        public readonly string $resolvedClass,
    ) {
        parent::__construct(sprintf(
            'Provider "%s" resolved to %s, which does not implement Core\\Notification\\Contracts\\NotificationChannelProvider. '.
            'Every adapter tagged as a notification channel provider must implement that interface.',
            $providerCode->toString(),
            $resolvedClass,
        ));
    }
}
