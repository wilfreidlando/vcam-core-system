<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use DomainException;

/**
 * Thrown by a payment provider adapter when the provider is
 * administratively disabled (`ProviderConfig::enabled = false`).
 *
 * The caller should not retry — the integration is off. Surfaced
 * before any HTTP traffic so it is safe to expose to clients.
 */
final class PaymentProviderDisabledException extends DomainException {}
