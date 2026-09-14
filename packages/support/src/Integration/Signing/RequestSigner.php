<?php

declare(strict_types=1);

namespace Core\Support\Integration\Signing;

use Core\Support\Integration\Configuration\SignaturePolicy;

/**
 * Mutates an outgoing request to add a request signature.
 *
 * The signer is invoked by `ExternalApiCaller` after authentication and
 * before transport. The signer may:
 *  - add a signature header (the canonical case)
 *  - add a timestamp header (for replay protection on the receiving side)
 *  - mutate the body (e.g. when the signature covers the canonicalised body
 *    — kept rare; document in the implementation if you do this)
 *
 * Implementations MUST be stateless apart from the policy they were
 * configured with. They MUST be safe to call from any HTTP worker
 * process. They MUST NOT log the secret.
 */
interface RequestSigner
{
    /**
     * Add the signature headers to `$headers` in place.
     *
     * @param array<string, string> $headers Mutable header bag
     * @param string $body Canonical body string (already serialised)
     * @param int $timestamp Unix timestamp of the signature
     */
    public function sign(
        SignaturePolicy $policy,
        array &$headers,
        string $body,
        int $timestamp,
    ): void;
}
