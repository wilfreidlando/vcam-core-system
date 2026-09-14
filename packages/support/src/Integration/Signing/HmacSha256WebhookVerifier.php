<?php

declare(strict_types=1);

namespace Core\Support\Integration\Signing;

use Core\Support\Integration\Configuration\SignaturePolicy;

/**
 * Default webhook signature verifier.
 *
 * Pure, stateless, and uses `hash_equals()` for the comparison so a
 * timing-attack cannot leak the expected signature byte-by-byte. The
 * verifier depends only on its configured `SignaturePolicy`, never on a
 * clock — the caller passes `now` so tests can pin time deterministically.
 *
 * Header lookup is case-insensitive: webhook headers arrive in
 * arbitrary case, the verifier lower-cases keys before lookup.
 */
final class HmacSha256WebhookVerifier implements WebhookSignatureVerifier
{
    public function __construct(private readonly SignaturePolicy $policy) {}

    public function verify(string $rawBody, array $headers, int $now): void
    {
        $lowerHeaders = [];
        foreach ($headers as $key => $value) {
            $lowerHeaders[strtolower($key)] = $value;
        }

        $timestampRaw = $lowerHeaders[strtolower($this->policy->timestampHeader)] ?? null;
        if (! is_string($timestampRaw) || ! is_numeric($timestampRaw)) {
            throw new UnverifiableWebhookSignature(sprintf(
                'Webhook is missing the %s header or it is not numeric.',
                $this->policy->timestampHeader,
            ));
        }

        /** @var string $signatureRaw */
        $signatureRaw = $lowerHeaders[strtolower($this->policy->signatureHeader)] ?? '';
        if ($signatureRaw === '') {
            throw new UnverifiableWebhookSignature(sprintf(
                'Webhook is missing the %s header.',
                $this->policy->signatureHeader,
            ));
        }

        $timestamp = (int) $timestampRaw;
        $skew = abs($now - $timestamp);
        if ($skew > $this->policy->maxClockSkewSeconds) {
            throw new WebhookTimestampOutOfRange(sprintf(
                'Webhook timestamp %d is %d seconds away from now (max allowed: %d).',
                $timestamp,
                $skew,
                $this->policy->maxClockSkewSeconds,
            ));
        }

        $rendered = strtr(
            $this->policy->signedPayloadTemplate,
            [
                '{timestamp}' => (string) $timestamp,
                '{body}' => $rawBody,
            ],
        );
        $expected = hash_hmac('sha256', $rendered, $this->policy->secret);

        if (! hash_equals($expected, $signatureRaw)) {
            throw new UnverifiableWebhookSignature('Webhook signature does not match.');
        }
    }
}
