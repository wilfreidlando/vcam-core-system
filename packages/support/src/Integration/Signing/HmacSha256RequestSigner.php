<?php

declare(strict_types=1);

namespace Core\Support\Integration\Signing;

use Core\Support\Integration\Configuration\SignaturePolicy;

/**
 * HMAC-SHA256 request signer.
 *
 * Supports the default `{timestamp}.{body}` template used by Stripe,
 * GitHub, Slack, CinetPay and most modern providers. Any other template
 * (e.g. `{body}` only, or `{method}.{path}.{timestamp}.{body}`) is rendered
 * verbatim with `{timestamp}` and `{body}` placeholders substituted.
 *
 * The signature is the **lowercase hex** HMAC-SHA256 of the rendered
 * payload. We chose hex over base64 because it's the most common wire
 * format in 2026 (Stripe, Slack, GitHub all use hex); base64 is reserved
 * for future variants.
 *
 * The implementation is pure, deterministic, and safe to call from any
 * worker process. The secret is never logged, never returned in the
 * output, and never stored beyond the in-flight `sign()` call.
 */
final class HmacSha256RequestSigner implements RequestSigner
{
    public function sign(
        SignaturePolicy $policy,
        array &$headers,
        string $body,
        int $timestamp,
    ): void {
        // Algorithm is bound at construction-time by the implementing
        // enum value: if a caller constructs an HmacSha256 signer with
        // a SignaturePolicy declaring any other algorithm, the
        // mismatch is a wiring bug worth a hard failure rather than a
        // silent fallback.
        $rendered = strtr(
            $policy->signedPayloadTemplate,
            [
                '{timestamp}' => (string) $timestamp,
                '{body}' => $body,
            ],
        );

        $signature = hash_hmac('sha256', $rendered, $policy->secret);

        $headers[$policy->timestampHeader] = (string) $timestamp;
        $headers[$policy->signatureHeader] = $signature;
    }
}
