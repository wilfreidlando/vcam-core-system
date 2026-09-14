<?php

declare(strict_types=1);

namespace Core\Support\Integration\Auth;

use Core\Support\Integration\Configuration\AuthKind;
use Core\Support\Integration\Configuration\AuthStrategy;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * Result of one auth provider's pass: the headers to merge on the
 * outgoing request, and an optional body delta (for credentials placed
 * in the body rather than headers).
 *
 * `body === null` means "body unchanged" — the default for header-based
 * auth strategies. A non-null `body` REPLACES the existing body, so
 * providers that want to add a key to the body should return the full
 * updated payload, not a diff. (Bodies are small and structured.)
 */
final readonly class AuthApplyResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public array $headers = [],
        public mixed $body = null,
    ) {}
}

/**
 * Produces the authentication headers — and optionally mutates the body
 * — for one outbound provider call.
 *
 * One implementation per `AuthKind` value. The `ExternalApiCaller` picks
 * the right provider from the iterable by reading `AuthStrategy::kind`,
 * never by inspecting the strategy itself — the strategy stays a
 * passive data object.
 *
 * The provider receives **both** the structural `AuthStrategy` (where to
 * fetch a token, which header to use) and the per-call `Credentials`
 * (the actual secret values). This separation lets one provider be
 * reused across multiple callers with different credentials (multi-tenant
 * SaaS).
 *
 * Implementations MUST be stateless apart from the injected dependencies.
 * Implementations MUST NOT log credentials — the foundation redacts
 * known secret headers and body fields after the fact, but the safest
 * posture is "the credential never enters a log line at all".
 */
interface AuthProvider
{
    public function supports(AuthKind $kind): bool;

    /**
     * @param array<string, string> $existingHeaders Headers already built by
     *                                               the caller — auth provider
     *                                               merges on top.
     * @param mixed $currentBody Body assembled by the
     *                           caller. Providers that place
     *                           a credential in the body
     *                           return the updated body
     *                           alongside the headers.
     */
    public function apply(
        ProviderCode $code,
        AuthStrategy $strategy,
        Credentials $credentials,
        array $existingHeaders,
        mixed $currentBody,
    ): AuthApplyResult;
}
