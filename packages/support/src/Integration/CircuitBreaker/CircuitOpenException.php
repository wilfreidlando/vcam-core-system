<?php

declare(strict_types=1);

namespace Core\Support\Integration\CircuitBreaker;

use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;

/**
 * Raised by `CircuitBreaker::guard()` when the breaker is open.
 *
 * Carries the provider code so the caller (or the API layer) can format a
 * useful error response. The exception is a regular `RuntimeException` —
 * it does not extend `ProviderException` because the failure is not the
 * provider's fault: the breaker decided not to call it at all.
 */
final class CircuitOpenException extends RuntimeException
{
    public function __construct(public readonly ProviderCode $providerCode)
    {
        parent::__construct(sprintf(
            'Circuit breaker is open for provider "%s"; call refused without HTTP traffic.',
            $providerCode->toString(),
        ));
    }
}
