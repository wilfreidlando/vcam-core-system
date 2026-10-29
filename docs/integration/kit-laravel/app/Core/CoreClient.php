<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one HTTP door to the Core: bearer key, JSON, one error type.
 *
 * - Every money route takes an **idempotency key** (`$key`): the same key means
 *   the same operation, so you may replay it after a timeout without paying twice.
 *   Use the id of YOUR order/transfer/payout — never a random value.
 * - Only calls that carry a key are retried (twice, on a network failure or a
 *   503): replaying anything else could do the thing twice.
 * - The key is also sent as `Correlation-Id`, so one id follows the operation
 *   through your logs, the Core's and the provider's.
 */
final class CoreClient
{
    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], ?string $key = null): array
    {
        return $this->send('POST', $path, $body, $key);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body = []): array
    {
        return $this->send('PUT', $path, $body, null);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data, ?string $key): array
    {
        try {
            $response = $this->request($key)->send($method, ltrim($path, '/'), $method === 'GET' ? ['query' => $data] : ['json' => (object) $data]);
        } catch (ConnectionException $e) {
            throw CoreException::unreachable(mb_substr($e->getMessage(), 0, 200));
        }

        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        throw $this->failure($response);
    }

    private function request(?string $key): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('core.base_url'), '/').'/v1/')
            ->withToken((string) config('core.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('core.connect_timeout'))
            ->timeout((int) config('core.timeout'));

        if ($key !== null) {
            $request = $request
                ->withHeaders(['Idempotency-Key' => $key, 'Correlation-Id' => $key])
                ->retry(2, 300, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false);
        }

        return $request;
    }

    private function failure(Response $response): CoreException
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $type = is_string($body['type'] ?? null) ? (string) preg_replace('#^.*/#', '', $body['type']) : 'http-'.$response->status();
        $retryAfter = $response->header('Retry-After');

        return new CoreException(
            $response->status(),
            $type,
            is_string($body['detail'] ?? null) ? $body['detail'] : ($response->reason() ?: 'Request failed'),
            $body,
            is_numeric($retryAfter) ? (int) $retryAfter : null,
            is_string($body['correlation_id'] ?? null) ? $body['correlation_id'] : null,
        );
    }
}
