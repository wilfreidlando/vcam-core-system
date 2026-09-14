<?php

declare(strict_types=1);

namespace Core\Support\Integration;

/**
 * Result of one outbound call to a provider.
 *
 * Built by the `HttpTransport`, consumed by `ExternalApiCaller` to
 * classify the result and ultimately returned (or transformed into an
 * exception) to the adapter.
 *
 * - `status` is the HTTP status code. Always present (a transport failure
 *    raises an exception, not a response).
 * - `headers` is the raw response header bag. Header names are normalised
 *   to lower-case by the transport.
 * - `body` is the parsed JSON body, or null if the response was empty or
 *   not JSON-parseable. For non-JSON providers, callers reach for `rawBody`.
 * - `rawBody` is the response body as a string. Always present (possibly
 *   empty), so signature verification on response bodies (rare but
 *   possible) can be done by adapters without re-reading the wire.
 * - `latencyMs` is the time the transport spent on the wire, in
 *   milliseconds. Useful for logging and circuit breaker decisions; not
 *   part of any SLA contract.
 */
final readonly class ExternalResponse
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public ?array $body = null,
        public string $rawBody = '',
        public int $latencyMs = 0,
    ) {}
}
