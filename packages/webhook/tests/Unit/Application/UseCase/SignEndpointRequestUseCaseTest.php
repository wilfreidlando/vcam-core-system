<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Application\UseCase;

use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use Core\Webhook\Application\UseCase\SignEndpointRequestUseCase;
use Core\Webhook\Contracts\SignEndpointRequestCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Tests\Unit\Fakes\FixedClock;
use Core\Webhook\Tests\Unit\Fakes\InMemoryWebhookEndpointRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SignEndpointRequestUseCaseTest extends TestCase
{
    private const PROJECT = 'project-1';

    private InMemoryWebhookEndpointRepository $endpoints;

    public function test_it_signs_the_body_with_the_endpoint_secret(): void
    {
        $this->endpoints->create($this->endpoint('secret-a'));

        $signed = $this->useCase()->sign(new SignEndpointRequestCommand(
            projectId: self::PROJECT,
            endpointId: 'endpoint-1',
            body: '{"page":1}',
        ));

        $timestamp = (string) (new DateTimeImmutable('2026-09-15T10:00:00Z'))->getTimestamp();

        self::assertSame($timestamp, $signed->headers['X-Timestamp']);
        self::assertSame(
            hash_hmac('sha256', $timestamp.'.'.'{"page":1}', 'secret-a'),
            $signed->headers['X-Signature'],
        );
    }

    public function test_an_empty_body_is_signed_as_the_timestamp_alone(): void
    {
        $this->endpoints->create($this->endpoint('secret-a'));

        $signed = $this->useCase()->sign(new SignEndpointRequestCommand(
            projectId: self::PROJECT,
            endpointId: 'endpoint-1',
            body: '',
        ));

        $timestamp = (string) (new DateTimeImmutable('2026-09-15T10:00:00Z'))->getTimestamp();

        self::assertSame(
            hash_hmac('sha256', $timestamp.'.', 'secret-a'),
            $signed->headers['X-Signature'],
        );
    }

    public function test_the_secret_never_appears_in_the_headers(): void
    {
        $this->endpoints->create($this->endpoint('super-secret'));

        $signed = $this->useCase()->sign(new SignEndpointRequestCommand(
            projectId: self::PROJECT,
            endpointId: 'endpoint-1',
            body: '{}',
        ));

        self::assertStringNotContainsString('super-secret', implode('|', $signed->headers));
    }

    public function test_it_signs_also_with_the_previous_secret_during_the_overlap(): void
    {
        $now = new DateTimeImmutable('2026-09-15T10:00:00Z');

        $this->endpoints->create($this->endpoint('secret-a', previousSecret: 'secret-b', previousExpiresAt: $now->modify('+1 hour')));

        $signed = $this->useCase()->sign(new SignEndpointRequestCommand(
            projectId: self::PROJECT,
            endpointId: 'endpoint-1',
            body: '{"page":1}',
        ));

        $timestamp = (string) $now->getTimestamp();

        self::assertSame(
            hash_hmac('sha256', $timestamp.'.'.'{"page":1}', 'secret-a'),
            $signed->headers['X-Signature'],
        );
        self::assertSame(
            hash_hmac('sha256', $timestamp.'.'.'{"page":1}', 'secret-b'),
            $signed->headers['X-Signature-Previous'],
        );
    }

    public function test_no_previous_signature_once_the_overlap_is_over(): void
    {
        $now = new DateTimeImmutable('2026-09-15T10:00:00Z');

        $this->endpoints->create($this->endpoint('secret-a', previousSecret: 'secret-b', previousExpiresAt: $now->modify('-1 second')));

        $signed = $this->useCase()->sign(new SignEndpointRequestCommand(
            projectId: self::PROJECT,
            endpointId: 'endpoint-1',
            body: '{}',
        ));

        self::assertArrayNotHasKey('X-Signature-Previous', $signed->headers);
    }

    public function test_it_refuses_an_unknown_endpoint(): void
    {
        $this->expectException(WebhookEndpointNotFound::class);

        $this->useCase()->sign(new SignEndpointRequestCommand(
            projectId: self::PROJECT,
            endpointId: 'endpoint-unknown',
            body: '{}',
        ));
    }

    public function test_it_refuses_an_endpoint_of_another_project(): void
    {
        $this->endpoints->create($this->endpoint('secret-a'));

        $this->expectException(WebhookEndpointNotFound::class);

        $this->useCase()->sign(new SignEndpointRequestCommand(
            projectId: 'project-2',
            endpointId: 'endpoint-1',
            body: '{}',
        ));
    }

    protected function setUp(): void
    {
        $this->endpoints = new InMemoryWebhookEndpointRepository;
    }

    private function useCase(): SignEndpointRequestUseCase
    {
        return new SignEndpointRequestUseCase(
            $this->endpoints,
            new HmacSha256RequestSigner,
            new FixedClock,
        );
    }

    private function endpoint(
        string $secret,
        ?string $previousSecret = null,
        ?DateTimeImmutable $previousExpiresAt = null,
    ): WebhookEndpoint {
        return new WebhookEndpoint(
            id: 'endpoint-1',
            projectId: self::PROJECT,
            url: 'https://example.test/webhooks',
            secret: $secret,
            subscribedEventTypes: ['*'],
            active: true,
            previousSecret: $previousSecret,
            previousSecretExpiresAt: $previousExpiresAt,
        );
    }
}
