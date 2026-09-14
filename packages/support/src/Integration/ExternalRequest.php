<?php

declare(strict_types=1);

namespace Core\Support\Integration;

use Core\Support\Integration\Provider\ProviderCode;

/**
 * Description of one outbound call to a provider.
 *
 * The caller (a concrete adapter) builds this and hands it to
 * `ExternalApiCaller::send()`. The caller never builds an `HttpClient`
 * request directly — that's the foundation's job.
 *
 * Fields:
 *  - `method`, `path`, `query`, `headers`, `body` describe the request shape
 *  - `providerCode` ties the call to a provider (used for circuit breaker
 *    key, log context, and per-provider cache keys). The caller passes it
 *    explicitly; it must match the `ProviderConfig::code` provided to
 *    `send()`.
 *  - `correlationId` is propagated as a header. When null, the
 *    `ExternalApiCaller` reads it from the request context (set by the
 *    `CorrelationId` middleware in the same package) so every log line
 *    and provider call share the same trace identifier.
 *  - `idempotencyKey` is forwarded as the `Idempotency-Key` HTTP header
 *    when provided. The foundation does not persist or check it — that
 *    belongs to the idempotency middleware (Lot 2 / ADR-0025).
 *
 * The VO is immutable and `readonly` so it can be safely shared between
 * retries without anyone mutating it underneath.
 */
final readonly class ExternalRequest
{
    /**
     * @param array<string, scalar|list<scalar>> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public HttpMethod $method,
        public string $path,
        public ProviderCode $providerCode,
        public array $query = [],
        public array $headers = [],
        public mixed $body = null,
        public ?string $correlationId = null,
        public ?string $idempotencyKey = null,
        /** Short identifier for the operation, e.g. "mtn.initiate". For logs and AdapterLog (Lot 3+). */
        public ?string $operation = null,
        /**
         * Optional per-call credentials. When supplied, each non-null field
         * overrides the corresponding field in the `ProviderConfig`'s default
         * credentials. Use this for multi-tenant / per-merchant credentials.
         */
        public ?Credentials $credentials = null,
    ) {}
}
