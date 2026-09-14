<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Signing;

use Core\Support\Integration\Configuration\SignatureAlgorithm;
use Core\Support\Integration\Configuration\SignaturePolicy;
use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use Core\Support\Integration\Signing\HmacSha256WebhookVerifier;
use Core\Support\Integration\Signing\UnverifiableWebhookSignature;
use Core\Support\Integration\Signing\WebhookTimestampOutOfRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacSha256WebhookVerifierTest extends TestCase
{
    private const SECRET = 'the-secret';

    #[Test]
    public function valid_webhook_passes(): void
    {
        $signed = $this->makeSignedRequest('{"event":"test"}', 1700000000);

        $verifier = new HmacSha256WebhookVerifier(new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: self::SECRET,
        ));

        $verifier->verify($signed['body'], $signed['headers'], $signed['now']);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function wrong_secret_fails(): void
    {
        $signed = $this->makeSignedRequest(
            'body',
            1700000000,
            secret: 'wrong-secret',
        );

        $verifier = new HmacSha256WebhookVerifier(new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: self::SECRET,
        ));

        $this->expectException(UnverifiableWebhookSignature::class);
        $verifier->verify($signed['body'], $signed['headers'], $signed['now']);
    }

    #[Test]
    public function tampered_body_fails(): void
    {
        $signed = $this->makeSignedRequest('original-body', 1700000000);

        $verifier = new HmacSha256WebhookVerifier(new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: self::SECRET,
        ));

        $this->expectException(UnverifiableWebhookSignature::class);
        $verifier->verify('tampered-body', $signed['headers'], $signed['now']);
    }

    #[Test]
    public function stale_timestamp_is_rejected(): void
    {
        $signed = $this->makeSignedRequest('b', 1700000000);

        $verifier = new HmacSha256WebhookVerifier(new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: self::SECRET,
            maxClockSkewSeconds: 60,
        ));

        $this->expectException(WebhookTimestampOutOfRange::class);
        $verifier->verify($signed['body'], $signed['headers'], 1700001000);
    }

    #[Test]
    public function headers_lookup_is_case_insensitive(): void
    {
        $signed = $this->makeSignedRequest('body', 1700000000);

        $verifier = new HmacSha256WebhookVerifier(new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: self::SECRET,
        ));

        $lowerHeaders = [
            'x-timestamp' => $signed['headers']['X-Timestamp'],
            'x-signature' => $signed['headers']['X-Signature'],
        ];

        $verifier->verify($signed['body'], $lowerHeaders, $signed['now']);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function missing_timestamp_header_fails(): void
    {
        $verifier = new HmacSha256WebhookVerifier(new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: self::SECRET,
        ));

        $this->expectException(UnverifiableWebhookSignature::class);
        $verifier->verify('body', ['X-Signature' => 'abc'], 1700000000);
    }

    #[Test]
    public function missing_signature_header_fails(): void
    {
        $verifier = new HmacSha256WebhookVerifier(new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: self::SECRET,
        ));

        $this->expectException(UnverifiableWebhookSignature::class);
        $verifier->verify('body', ['X-Timestamp' => '1700000000'], 1700000000);
    }

    /**
     * @return array{body: string, headers: array<string, string>, now: int}
     */
    private function makeSignedRequest(string $body, int $timestamp, string $secret = self::SECRET): array
    {
        $signer = new HmacSha256RequestSigner;
        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: $secret,
        );
        /** @var array<string, string> $headers */
        $headers = [];
        $signer->sign($policy, $headers, $body, $timestamp);

        return [
            'body' => $body,
            'headers' => [
                'X-Timestamp' => (string) $timestamp,
                'X-Signature' => $headers['X-Signature'],
            ],
            'now' => $timestamp,
        ];
    }
}
