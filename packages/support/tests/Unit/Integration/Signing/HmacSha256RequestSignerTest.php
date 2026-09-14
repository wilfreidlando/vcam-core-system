<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration\Signing;

use Core\Support\Integration\Configuration\SignatureAlgorithm;
use Core\Support\Integration\Configuration\SignaturePolicy;
use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacSha256RequestSignerTest extends TestCase
{
    #[Test]
    public function it_signs_with_timestamp_body_template(): void
    {
        $signer = new HmacSha256RequestSigner;
        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: 'the-secret',
            signatureHeader: 'X-Signature',
            timestampHeader: 'X-Timestamp',
            signedPayloadTemplate: '{timestamp}.{body}',
        );

        $headers = [];
        $timestamp = 1700000000;
        $signer->sign($policy, $headers, '{"foo":"bar"}', $timestamp);

        self::assertSame((string) $timestamp, $headers['X-Timestamp']);

        $expected = hash_hmac('sha256', '1700000000.{"foo":"bar"}', 'the-secret');
        self::assertSame($expected, $headers['X-Signature']);
    }

    #[Test]
    public function different_timestamps_produce_different_signatures(): void
    {
        $signer = new HmacSha256RequestSigner;
        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: 's',
        );

        $a = [];
        $b = [];
        $signer->sign($policy, $a, 'body', 100);
        $signer->sign($policy, $b, 'body', 200);

        self::assertNotSame($a['X-Signature'], $b['X-Signature']);
    }

    #[Test]
    public function different_bodies_produce_different_signatures(): void
    {
        $signer = new HmacSha256RequestSigner;
        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: 's',
        );

        $a = [];
        $b = [];
        $signer->sign($policy, $a, 'body-1', 100);
        $signer->sign($policy, $b, 'body-2', 100);

        self::assertNotSame($a['X-Signature'], $b['X-Signature']);
    }

    #[Test]
    public function custom_template_is_respected(): void
    {
        $signer = new HmacSha256RequestSigner;
        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: 's',
            signedPayloadTemplate: '{body}',
        );

        $headers = [];
        $signer->sign($policy, $headers, 'payload', 123);

        $expected = hash_hmac('sha256', 'payload', 's');
        self::assertSame($expected, $headers['X-Signature']);
    }

    #[Test]
    public function signature_is_a_lowercase_hex_string(): void
    {
        $signer = new HmacSha256RequestSigner;
        $policy = new SignaturePolicy(
            algorithm: SignatureAlgorithm::HmacSha256,
            secret: 's',
        );

        $headers = [];
        $signer->sign($policy, $headers, 'b', 1);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $headers['X-Signature']);
    }
}
