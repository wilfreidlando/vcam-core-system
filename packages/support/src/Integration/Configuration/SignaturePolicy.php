<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use InvalidArgumentException;

/**
 * HMAC request-signing policy.
 *
 * The signed payload template uses named placeholders. The default,
 * `{timestamp}.{body}`, matches Stripe, GitHub, Slack and most modern
 * providers. A provider that expects a different layout (e.g. just `{body}`)
 * declares its own template in `config/providers.php`.
 *
 * `maxClockSkewSeconds` bounds the accepted clock drift on the receiving
 * end (webhook verification only). Outbound signing does not use it.
 */
final readonly class SignaturePolicy
{
    public function __construct(
        public SignatureAlgorithm $algorithm,
        public string $secret,
        public string $signatureHeader = 'X-Signature',
        public string $timestampHeader = 'X-Timestamp',
        public string $signedPayloadTemplate = '{timestamp}.{body}',
        public int $maxClockSkewSeconds = 300,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['algorithm']) || ! is_string($data['algorithm'])) {
            throw new InvalidArgumentException('SignaturePolicy.algorithm is required and must be a string.');
        }
        $algorithm = SignatureAlgorithm::tryFrom($data['algorithm']);
        if ($algorithm === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown SignaturePolicy.algorithm "%s". Supported: %s.',
                $data['algorithm'],
                implode(', ', array_map(static fn (SignatureAlgorithm $a) => $a->value, SignatureAlgorithm::cases())),
            ));
        }
        if (! isset($data['secret']) || ! is_string($data['secret']) || $data['secret'] === '') {
            throw new InvalidArgumentException('SignaturePolicy.secret must be a non-empty string.');
        }

        return new self(
            algorithm: $algorithm,
            secret: $data['secret'],
            signatureHeader: self::stringOr($data, 'signature_header', 'X-Signature'),
            timestampHeader: self::stringOr($data, 'timestamp_header', 'X-Timestamp'),
            signedPayloadTemplate: self::stringOr($data, 'signed_payload_template', '{timestamp}.{body}'),
            maxClockSkewSeconds: isset($data['max_clock_skew_seconds']) && is_numeric($data['max_clock_skew_seconds'])
                ? (int) $data['max_clock_skew_seconds']
                : 300,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringOr(array $data, string $key, string $default): string
    {
        if (! isset($data[$key]) || ! is_string($data[$key])) {
            return $default;
        }

        return $data[$key];
    }
}
