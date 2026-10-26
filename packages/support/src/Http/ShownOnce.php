<?php

declare(strict_types=1);

namespace Core\Support\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0081 S-07 / S-08 — a secret is usable in an API response **once**, at
 * its creation, and is never stored in clear.
 *
 * A controller that returns such a secret marks its top-level JSON keys
 * here. Anything that keeps a copy of a response must drop them: today the
 * idempotency middleware, which stores the response to replay it — without
 * this, a replay hands the secret out a second time and the store keeps it
 * in clear for as long as the row lives.
 *
 * The marker is an internal header, removed before the response leaves the
 * application (`take()`): it says which keys to forget, never their values.
 */
final class ShownOnce
{
    public const HEADER = 'X-Core-Shown-Once';

    /**
     * Marks the response's top-level JSON keys that carry a secret shown once.
     */
    public static function mark(Response $response, string ...$keys): Response
    {
        if ($keys !== []) {
            $response->headers->set(self::HEADER, implode(',', $keys));
        }

        return $response;
    }

    /**
     * The keys the response marked — and the marker removed, so it never
     * reaches the client.
     *
     * @return list<string>
     */
    public static function take(Response $response): array
    {
        $raw = $response->headers->get(self::HEADER);
        $response->headers->remove(self::HEADER);

        if ($raw === null || $raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn (string $key): bool => $key !== '',
        ));
    }

    /**
     * The body without the marked keys.
     *
     * @param array<string, mixed> $body
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function forget(array $body, array $keys): array
    {
        foreach ($keys as $key) {
            unset($body[$key]);
        }

        return $body;
    }
}
