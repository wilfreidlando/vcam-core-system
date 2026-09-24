<?php

declare(strict_types=1);

namespace Core\Support\Integration\Exception;

use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;
use Throwable;

/**
 * Base exception for everything that can go wrong in the integration
 * foundation.
 *
 * Carries the `providerCode` so callers can attribute the failure to a
 * specific integration without parsing exception messages, and an
 * `httpStatus` when the failure is the result of a provider response, and
 * the provider's parsed `responseBody` (never copied into the message,
 * which reaches API callers) so an ADAPTER can recognise a specific,
 * actionable refusal and translate it.
 *
 * Subclasses are typed for the failure category so the caller can react
 * appropriately (retry on transient, fail-fast on permanent, refresh token
 * on auth). Specific subclasses carry specific information (see
 * `ProviderTransientError`, `ProviderPermanentError`, `ProviderAuthError`,
 * `ProviderDisabledException`).
 */
abstract class ProviderException extends RuntimeException
{
    /**
     * @param array<mixed>|null $responseBody
     */
    public function __construct(
        string $message,
        public readonly ProviderCode $providerCode,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
