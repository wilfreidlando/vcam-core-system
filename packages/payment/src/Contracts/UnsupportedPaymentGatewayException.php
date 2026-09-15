<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;

/**
 * Thrown by `PaymentGatewayRegistry::gatewayFor()` when the underlying
 * generic registry returns a gateway that does not implement the
 * payment-specific `PaymentGateway` interface. The most likely cause
 * is a misconfigured module that tagged a `ProviderGateway` under a
 * `payment.*` provider code but its class doesn't actually extend
 * `PaymentGateway`.
 *
 * The error names the resolved class so the operator can fix the
 * wiring in one read.
 */
final class UnsupportedPaymentGatewayException extends RuntimeException
{
    public function __construct(
        public readonly ProviderCode $providerCode,
        public readonly string $resolvedClass,
    ) {
        parent::__construct(sprintf(
            'Provider "%s" resolved to %s, which does not implement Core\\Payment\\Contracts\\PaymentGateway. '.
            'Every adapter tagged as a payment gateway must implement that interface.',
            $providerCode->toString(),
            $resolvedClass,
        ));
    }
}
