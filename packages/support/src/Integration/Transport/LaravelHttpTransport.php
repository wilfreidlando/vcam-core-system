<?php

declare(strict_types=1);

namespace Core\Support\Integration\Transport;

use Core\Support\Clock;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;
use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Provider\ProviderCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use JsonException;
use Throwable;

/**
 * Default `HttpTransport` implementation, backed by Laravel's HTTP client.
 *
 * Laravel's HTTP client wraps Guzzle. The factory is shared (one HTTP
 * client per process) so connection pooling is preserved across calls.
 *
 * Timeouts:
 *  - `connect_timeout` is the TCP/TLS handshake timeout.
 *  - `timeout` is the total request timeout.
 *  - We never set a `stream` timeout; the foundation is request-response,
 *    not streaming.
 *
 * Errors:
 *  - `ConnectionException` (network, DNS, TLS handshake, timeout) is
 *    translated to `ProviderTransientError`.
 *  - Any other transport exception (rare; e.g. invalid URL) is translated
 *    to `ProviderPermanentError`.
 *
 * The transport does NOT classify HTTP status codes — that's the
 * caller's job (4xx is not always a failure, 2xx is not always success
 * when the body says otherwise).
 */
final readonly class LaravelHttpTransport implements HttpTransport
{
    public function __construct(
        private HttpFactory $factory,
        private Clock $clock,
    ) {}

    public function send(
        HttpMethod $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        ?float $timeoutSeconds = null,
    ): ExternalResponse {
        $startedAt = $this->nowMs();

        $resolvedTimeout = $timeoutSeconds ?? 30.0;
        $connectTimeout = 2;
        $timeoutInt = (int) ceil($resolvedTimeout);
        if ($timeoutInt < 1) {
            $timeoutInt = 1;
        }

        /** @var \Illuminate\Http\Client\PendingRequest $pending */
        // @phpstan-ignore-next-line (Laravel HTTP client generic — false positive with PendingRequest<false>)
        $pending = $this->factory
            ->withHeaders($headers)
            ->timeout($timeoutInt)
            ->connectTimeout($connectTimeout)
            ->acceptJson()
            ->withOptions([
                'http_errors' => false,
            ]);

        if ($body !== null) {
            if (is_array($body)) {
                $serialised = json_encode($body, JSON_THROW_ON_ERROR);
            } else {
                $serialised = $body;
            }
            /** @var \Illuminate\Http\Client\PendingRequest $pending */
            $pending = $pending->withBody($serialised, 'application/json');
        }

        try {
            $response = match ($method) {
                HttpMethod::GET => $pending->get($url),
                HttpMethod::POST => $pending->post($url),
                HttpMethod::PUT => $pending->put($url),
                HttpMethod::PATCH => $pending->patch($url),
                HttpMethod::DELETE => $pending->delete($url),
            };
        } catch (ConnectionException $e) {
            throw new ProviderTransientError(
                sprintf('HTTP transport failed for %s %s: %s', $method->value, $url, $e->getMessage()),
                ProviderCode::fromString('transport'),
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new ProviderPermanentError(
                sprintf('HTTP transport failed for %s %s: %s', $method->value, $url, $e->getMessage()),
                ProviderCode::fromString('transport'),
                previous: $e,
            );
        }

        $endedAt = $this->nowMs();

        return $this->adaptResponse($response, max(0, $endedAt - $startedAt));
    }

    private function nowMs(): int
    {
        $now = $this->clock->now();

        return $now->getTimestamp() * 1000 + (int) ((int) $now->format('u') / 1000);
    }

    private function adaptResponse(Response $response, int $latencyMs): ExternalResponse
    {
        $rawBody = $response->body();
        /** @var array<string, mixed>|null $body */
        $body = null;
        if ($rawBody !== '') {
            try {
                $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    $body = $decoded;
                }
            } catch (JsonException) {
                // Non-JSON body; keep rawBody, leave body null.
            }
        }

        /** @var array<string, string> $headers */
        $headers = [];
        /** @var array<int|string, mixed> $rawHeaders */
        $rawHeaders = $response->headers();
        foreach ($rawHeaders as $name => $values) {
            if (is_array($values) && isset($values[0]) && is_scalar($values[0])) {
                $headers[(string) $name] = (string) $values[0];
            }
        }

        return new ExternalResponse(
            status: $response->status(),
            headers: $headers,
            body: $body,
            rawBody: $rawBody,
            latencyMs: $latencyMs,
        );
    }
}
