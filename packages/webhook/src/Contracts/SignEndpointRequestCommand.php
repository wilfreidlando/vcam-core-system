<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * The request the Core wants signed, and the endpoint it is addressed to.
 *
 * `body` is the **raw** body: the signature covers the bytes, not a
 * re-serialisation of them. An empty body (a read without parameter) signs
 * as `timestamp.` — the same convention the events use.
 */
final readonly class SignEndpointRequestCommand
{
    public function __construct(
        public string $projectId,
        public string $endpointId,
        public string $body,
        /** The timestamp to sign with; null means "now". */
        public ?int $timestamp = null,
    ) {}
}
