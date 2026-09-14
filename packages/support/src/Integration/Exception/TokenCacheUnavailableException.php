<?php

declare(strict_types=1);

namespace Core\Support\Integration\Exception;

use Core\Support\Integration\Provider\ProviderCode;

/**
 * Raised when the bearer authentication provider is used without a
 * configured `TokenCache`. The bearer flow requires caching the OAuth
 * token across calls (and across worker processes); an in-memory default
 * is not safe in production because two workers would each refresh the
 * token independently, producing audit noise and minor extra load.
 *
 * The application shell is expected to bind a real `TokenCache` (Valkey,
 * Redis, …) before serving any bearer-authenticated traffic. This
 * exception makes the missing binding explicit and loud instead of
 * silently degrading to one-token-per-request.
 */
final class TokenCacheUnavailableException extends ProviderException
{
    public static function forCode(ProviderCode $code): self
    {
        return new self(
            sprintf(
                'Provider "%s" uses Bearer auth but no TokenCache is bound. '.
                'Bind a TokenCache implementation (e.g. Valkey-backed) in the application shell.',
                $code->toString(),
            ),
            $code,
        );
    }
}
