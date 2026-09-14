<?php

declare(strict_types=1);

namespace Core\Support\Integration;

use Core\Support\Clock;
use Core\Support\Integration\Auth\AuthProvider;
use Core\Support\Integration\CircuitBreaker\CircuitBreaker;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Exception\ProviderAuthError;
use Core\Support\Integration\Exception\ProviderDisabledException;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;
use Core\Support\Integration\Redaction\PayloadRedactor;
use Core\Support\Integration\Signing\RequestSigner;
use Core\Support\Integration\Transport\HttpTransport;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Default `ExternalApiCaller` implementation.
 *
 * The pipeline order is the contract documented on the `ExternalApiCaller`
 * interface. Read the interface first; the implementation just executes
 * it step by step with explicit error handling.
 *
 * Notable decisions:
 *  - **Correlation id is read from Laravel `Context`** when the caller
 *    did not pass one. The `CorrelationId` middleware writes the inbound
 *    id there, so the foundation stays decoupled from HTTP concerns.
 *  - **Retry is in-process** (no queue, no sleep injection point).
 *    Production retries happen in the worker; this is the synchronous
 *    fast-path. A future async saga (Lot 3+) will build on top of
 *    `ContinueOperation` for longer waits.
 *  - **The HTTP redirect on 401 is NOT triggered.** The bearer provider
 *    refreshes the token proactively; a 401 from the provider means the
 *    token is fine but the request is not authorized.
 *  - **Logging is "one line per attempt"**, not one line per call. The
 *    final outcome is logged separately on success/failure. Both lines
 *    carry the same `correlation_id` so they correlate in log search.
 */
final readonly class ExternalApiCallerImpl implements ExternalApiCaller
{
    /** HTTP status codes that signal "retry might succeed". */
    private const TRANSIENT_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /** HTTP status codes that signal "auth issue, do not retry". */
    private const AUTH_STATUSES = [401, 403];

    /**
     * @param iterable<AuthProvider> $authProviders All `AuthProvider` implementations,
     *                                              tagged in the service container.
     */
    public function __construct(
        private HttpTransport $transport,
        private CircuitBreaker $circuitBreaker,
        private RequestSigner $signer,
        private PayloadRedactor $redactor,
        private Clock $clock,
        private LoggerInterface $logger,
        private iterable $authProviders,
        private CorrelationIdProvider $correlationIds,
    ) {}

    public function send(ProviderConfig $config, ExternalRequest $request): ExternalResponse
    {
        $this->assertProviderCodesMatch($config, $request);
        $this->assertEnabled($config);
        $this->circuitBreaker->guard($config->code);

        $contextCorrelation = $this->correlationIds->current();
        $correlationId = $request->correlationId
            ?? ($contextCorrelation ?? '');

        $credentials = $this->resolveCredentials($config, $request);
        $authResult = $this->applyAuthentication($config, $request, $credentials, $correlationId);

        $headers = $this->lowerHeaderKeys($authResult->headers);
        $body = $authResult->body ?? $request->body;

        $bodyForSigning = $this->serialiseBody($body, $headers);
        $timestamp = $this->clock->now()->getTimestamp();

        if ($config->signature !== null) {
            $this->signer->sign($config->signature, $headers, $bodyForSigning, $timestamp);
        }

        $url = rtrim($config->baseUrl, '/').'/'.ltrim($request->path, '/');

        $maxAttempts = $config->retries->maxAttempts;
        $transientError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $attemptStart = $this->clock->now()->getTimestamp() * 1000
                + (int) ((float) $this->clock->now()->format('u') / 1000);

            try {
                /** @var string|array<mixed>|null $transportBody */
                $transportBody = is_array($body) || is_string($body) || $body === null
                    ? $body
                    : null;

                $response = $this->transport->send(
                    method: $request->method,
                    url: $this->appendQuery($url, $request->query),
                    headers: $headers,
                    body: $transportBody,
                    timeoutSeconds: $config->timeouts->requestSeconds,
                );
            } catch (ProviderTransientError $e) {
                $transientError = $e;
                $this->logger->info('integration.attempt.transient', [
                    'provider' => $config->code->toString(),
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                    'correlation_id' => $correlationId,
                    'operation' => $request->operation,
                ]);
                $this->circuitBreaker->recordFailure($config->code);
                $this->sleepBeforeNextAttempt($config, $attempt);

                continue;
            }

            $latencyMs = max(
                0,
                $this->clock->now()->getTimestamp() * 1000
                    + (int) ((float) $this->clock->now()->format('u') / 1000)
                    - $attemptStart,
            );

            $this->logger->info('integration.attempt.completed', [
                'provider' => $config->code->toString(),
                'attempt' => $attempt,
                'status' => $response->status,
                'latency_ms' => $latencyMs,
                'correlation_id' => $correlationId,
                'operation' => $request->operation,
                'redacted_headers' => $this->redactor->redactHeaders($response->headers),
                'redacted_body' => $this->redactor->redact($response->body),
            ]);

            if ($config->debug) {
                $this->logger->debug('integration.debug.completed', [
                    'provider' => $config->code->toString(),
                    'attempt' => $attempt,
                    'status' => $response->status,
                    'latency_ms' => $latencyMs,
                    'correlation_id' => $correlationId,
                    'operation' => $request->operation,
                    'url' => $url,
                    'method' => $request->method->value,
                    'redacted_headers' => $this->redactor->redactHeaders($headers),
                    'redacted_request' => $this->redactor->redact($body),
                    'redacted_response' => $this->redactor->redact($response->body),
                ]);
            }

            if ($response->status >= 200 && $response->status < 300) {
                $this->circuitBreaker->recordSuccess($config->code);

                return $response;
            }

            if (in_array($response->status, self::AUTH_STATUSES, true)) {
                $this->circuitBreaker->recordFailure($config->code);
                throw new ProviderAuthError(
                    sprintf(
                        'Provider "%s" returned HTTP %d (auth).',
                        $config->code->toString(),
                        $response->status,
                    ),
                    $config->code,
                    httpStatus: $response->status,
                );
            }

            if (in_array($response->status, self::TRANSIENT_STATUSES, true)
                || in_array($response->status, $config->retries->retryableStatuses, true)
            ) {
                $transientError = new ProviderTransientError(
                    sprintf(
                        'Provider "%s" returned HTTP %d (transient).',
                        $config->code->toString(),
                        $response->status,
                    ),
                    $config->code,
                    httpStatus: $response->status,
                );
                $this->circuitBreaker->recordFailure($config->code);
                $this->sleepBeforeNextAttempt($config, $attempt);

                continue;
            }

            $this->circuitBreaker->recordFailure($config->code);
            throw new ProviderPermanentError(
                sprintf(
                    'Provider "%s" returned HTTP %d (permanent).',
                    $config->code->toString(),
                    $response->status,
                ),
                $config->code,
                httpStatus: $response->status,
            );
        }

        // All attempts exhausted.
        $this->circuitBreaker->recordFailure($config->code);
        throw $transientError ?? new ProviderTransientError(
            sprintf('Provider "%s" exhausted %d attempts.', $config->code->toString(), $maxAttempts),
            $config->code,
        );
    }

    private function assertProviderCodesMatch(ProviderConfig $config, ExternalRequest $request): void
    {
        if (! $config->code->equals($request->providerCode)) {
            throw new LogicException(sprintf(
                'ExternalRequest.providerCode (%s) does not match ProviderConfig.code (%s).',
                $request->providerCode->toString(),
                $config->code->toString(),
            ));
        }
    }

    private function assertEnabled(ProviderConfig $config): void
    {
        if (! $config->enabled) {
            throw ProviderDisabledException::forCode($config->code);
        }
    }

    /**
     * Add the standard envelope headers (Correlation-Id, Idempotency-Key,
     * Accept) to the request, and the auth headers if the request asks
     * for authentication.
     *
     * The auth provider is skipped when `requiresAuth = false` (e.g. for
     * the public-key-in-path MyCoolPay operations that don't carry the
     * private credential). In that case the adapter is expected to have
     * set any required headers directly in `ExternalRequest::headers`.
     *
     * The auth provider may also mutate the body (when the credential
     * must ride in the body, not in a header).
     */
    private function applyAuthentication(
        ProviderConfig $config,
        ExternalRequest $request,
        Credentials $credentials,
        string $correlationId,
    ): Auth\AuthApplyResult {
        $headers = $request->headers;

        if ($correlationId !== '') {
            $headers['Correlation-Id'] = $correlationId;
        }
        if ($request->idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $request->idempotencyKey;
        }
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';

        if (! $request->requiresAuth) {
            return new Auth\AuthApplyResult(
                headers: $headers,
                body: null,
            );
        }

        // Authentication — selected by kind, applied by the matching provider.
        foreach ($this->authProviders as $authProvider) {
            if ($authProvider->supports($config->auth->kind)) {
                return $authProvider->apply(
                    $config->code,
                    $config->auth,
                    $credentials,
                    $headers,
                    $request->body,
                );
            }
        }

        // No auth provider for this kind — return headers unchanged.
        return new Auth\AuthApplyResult(
            headers: $headers,
            body: null,
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function lowerHeaderKeys(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * Per-call credentials win over the provider's static defaults, on a
     * field-by-field basis. A null in the per-call credentials does NOT
     * wipe the default value — the merge is asymmetric on purpose so the
     * caller only states the values it overrides.
     */
    private function resolveCredentials(ProviderConfig $config, ExternalRequest $request): Credentials
    {
        if ($request->credentials === null) {
            return $config->defaultCredentials;
        }

        return $config->defaultCredentials->mergeWith($request->credentials);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function appendQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * @param array<string, string> $headers
     */
    private function serialiseBody(mixed $body, array $headers): string
    {
        if ($body === null) {
            return '';
        }
        if (is_string($body)) {
            return $body;
        }
        if (! is_array($body)) {
            return '';
        }

        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sleepBeforeNextAttempt(ProviderConfig $config, int $attempt): void
    {
        // Microsecond-sleep the appropriate backoff. Attempt is 1-based;
        // the first retry is "attempt 1", so the backoff exponent is attempt-1.
        $delayMs = $config->retries->delayMsForAttempt($attempt - 1);
        if ($delayMs <= 0) {
            return;
        }
        usleep($delayMs * 1000);
    }
}
