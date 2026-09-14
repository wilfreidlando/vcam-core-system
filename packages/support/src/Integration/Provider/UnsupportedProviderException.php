<?php

declare(strict_types=1);

namespace Core\Support\Integration\Provider;

use RuntimeException;

/**
 * Raised by `ProviderRegistry::gatewayFor()` and `configFor()` when no
 * adapter is registered for the requested provider code.
 *
 * The exception is not a `ProviderException` because the failure is
 * local to the application — no external provider was even contacted.
 */
final class UnsupportedProviderException extends RuntimeException
{
    public function __construct(public readonly ProviderCode $providerCode)
    {
        parent::__construct(sprintf(
            'No provider gateway is registered for code "%s".',
            $providerCode->toString(),
        ));
    }
}
