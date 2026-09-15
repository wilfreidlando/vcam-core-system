<?php

declare(strict_types=1);

namespace Core\Support\Integration\Provider;

use RuntimeException;

/**
 * Raised at boot when two tagged `ProviderGateway` instances claim the
 * same `providerCode()`. This is a configuration bug — the same code
 * can only be served by one gateway at a time, and silently overwriting
 * one with the other would cause request routing to drift between
 * deploys. Failing loudly at boot forces the operator to disambiguate.
 */
final class DuplicateProviderCodeException extends RuntimeException
{
    public function __construct(
        public readonly ProviderCode $providerCode,
        public readonly ProviderGateway $first,
        public readonly ProviderGateway $second,
    ) {
        parent::__construct(sprintf(
            'Two ProviderGateway instances claim the code "%s": %s and %s. '.
            'Codes must be unique across the tagged "provider.gateway" set — '.
            'domain-specific codes (e.g. "orange" for payment) should not collide with '.
            'the same code in another domain (notification, sms, …). '.
            'If you need the same provider code in two domains, namespace the '.
            'code per domain (e.g. "payment-orange" vs "notification-orange").',
            $providerCode->toString(),
            $first::class,
            $second::class,
        ));
    }
}
