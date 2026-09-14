<?php

declare(strict_types=1);

namespace Core\Support\Integration\Signing;

/**
 * Reverse operation of `RequestSigner`: verifies a signature on an
 * inbound webhook.
 *
 * The verifier and the signer use the same algorithm and the same secret
 * — the verification is just HMAC over the same rendered payload. The
 * difference is direction: a webhook arrives as bytes + headers, and we
 * must use the **raw** body for verification (re-serialising JSON would
 * reorder keys, change whitespace, and break the signature).
 *
 * Two checks happen, in order:
 *  1. **Signature** — the signature header matches HMAC of the rendered
 *     payload. If not, the webhook is rejected as forged.
 *  2. **Freshness** — the timestamp header is within `maxClockSkewSeconds`
 *     of "now". If not, the webhook is rejected as a replay.
 *
 * The order matters: a perfectly-fresh forged signature is still forged.
 */
interface WebhookSignatureVerifier
{
    /**
     * @param string $rawBody The exact bytes the provider signed.
     * @param array<string, string> $headers Inbound headers, lower-cased keys.
     *
     * @throws UnverifiableWebhookSignature when the signature does not match
     * @throws WebhookTimestampOutOfRange when the timestamp is outside the clock skew
     */
    public function verify(
        string $rawBody,
        array $headers,
        int $now,
    ): void;
}
