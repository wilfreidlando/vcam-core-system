<?php

declare(strict_types=1);

namespace Core\Support\Integration;

use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Exception\ProviderAuthError;
use Core\Support\Integration\Exception\ProviderDisabledException;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;

/**
 * The single point of outbound HTTP in the Core.
 *
 * Every adapter that wants to talk to a provider calls this — adapters
 * never instantiate an HTTP client. The caller describes the request
 * (URL, method, body, headers, idempotency key) and the foundation takes
 * care of:
 *
 *  1. **Disabled check** — if the provider is administratively disabled,
 *     refuse without any HTTP traffic.
 *  2. **Circuit breaker guard** — if the breaker is open, refuse without
 *     HTTP traffic.
 *  3. **Authentication** — selected by `ProviderConfig::auth`, applied to
 *     the headers (Bearer token cached and refreshed transparently).
 *  4. **Correlation** — the correlation id is read from the request
 *     context when the caller did not provide one, and forwarded as
 *     `Correlation-Id` on the wire.
 *  5. **Idempotency-Key** — forwarded when the caller provided one.
 *  6. **Signing** — when a `SignaturePolicy` is configured, the body and
 *     timestamp are signed and the signature headers are added.
 *  7. **Send** — the request is issued through `HttpTransport` with the
 *     configured timeouts.
 *  8. **Classification** — the HTTP status is mapped to either
 *     `success` (2xx), `ProviderTransientError` (408, 429, 5xx),
 *     `ProviderAuthError` (401, 403) or `ProviderPermanentError`
 *     (other 4xx).
 *  9. **Retry** — transient failures are retried per `RetryPolicy`,
 *     with exponential backoff and jitter. After the budget is
 *     exhausted, the last transient error is thrown.
 * 10. **Logging** — one structured log per call, with redacted
 *     headers/body, including provider code, status, latency, and
 *     correlation id.
 * 11. **Circuit breaker update** — success closes the breaker,
 *     transient failure increments the counter, transient failure after
 *     half-open reopens it.
 *
 * Each step is unit-tested individually AND as a whole. The pipeline is
 * the surface that ADR-0026 promises; any change here is a change to
 * the central guarantee that the rest of the codebase relies on.
 */
interface ExternalApiCaller
{
    /**
     * @throws ProviderDisabledException when the provider is administratively disabled
     * @throws CircuitOpenException when the circuit breaker is open
     * @throws ProviderTransientError after retries are exhausted on a transient error
     * @throws ProviderPermanentError on a non-retryable provider response
     * @throws ProviderAuthError on 401/403 from the provider
     */
    public function send(ProviderConfig $config, ExternalRequest $request): ExternalResponse;
}
