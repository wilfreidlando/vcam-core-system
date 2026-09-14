<?php

declare(strict_types=1);

namespace Core\Support\Integration\Transport;

use Core\Support\Integration\Exception\ProviderException;
use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;

/**
 * Port for outbound HTTP. Only this port (and its in-foundation impls) is
 * permitted to touch the network — guardrail G6c enforces this in CI.
 *
 * The transport is a thin abstraction. Its only responsibilities are:
 *  - apply the timeouts
 *  - send the request
 *  - return the parsed response
 *
 * Everything else — auth, signing, retries, circuit breaker, logging,
 * error classification — lives in `ExternalApiCallerImpl` and operates
 * on top of this port. Keeping the transport dumb makes it easy to swap
 * (Laravel `Http::` in production, a canned-response fake in tests).
 *
 * Implementations MUST throw `ProviderException` (not generic transport
 * exceptions) when the HTTP request cannot be completed. The caller
 * decides retry vs permanent based on the subclass.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param string|array<mixed>|null $body Already-serialised body, or array for JSON encoding.
     * @param float|null $timeoutSeconds Total request timeout. Null = use the transport's default.
     *
     * @throws ProviderException when the request cannot be completed
     */
    public function send(
        HttpMethod $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        ?float $timeoutSeconds = null,
    ): ExternalResponse;
}
