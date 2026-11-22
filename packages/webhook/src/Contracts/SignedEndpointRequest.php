<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * The headers that authenticate a request addressed to an endpoint.
 *
 * The secret is **not** here: it never leaves the `webhook` module. What
 * travels is the signature, the timestamp, and — while a rotation's overlap
 * lasts — the signature of the previous secret.
 */
final readonly class SignedEndpointRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public array $headers,
    ) {}
}
