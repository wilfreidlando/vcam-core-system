<?php

declare(strict_types=1);

namespace Core\Support\Tests\Unit\Integration;

use Core\Support\Integration\Auth\ApiKeyAuthProvider;
use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\Credentials;
use Core\Support\Integration\Exception\ProviderAuthError;
use Core\Support\Integration\Exception\ProviderDisabledException;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;
use Core\Support\Integration\ExternalApiCallerImpl;
use Core\Support\Integration\ExternalRequest;
use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Provider\ProviderCode;
use Core\Support\Integration\Redaction\PayloadRedactor;
use Core\Support\Integration\Signing\HmacSha256RequestSigner;
use Core\Support\Integration\Signing\RequestSigner;
use Core\Support\Tests\Unit\Integration\Support\FakeCircuitBreaker;
use Core\Support\Tests\Unit\Integration\Support\FakeClock;
use Core\Support\Tests\Unit\Integration\Support\FakeCorrelationIdProvider;
use Core\Support\Tests\Unit\Integration\Support\FakeHttpTransport;
use Core\Support\Tests\Unit\Integration\Support\TransportExceptionStub;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ExternalApiCallerImplTest extends TestCase
{
    private const PROVIDER_CODE = 'cinetpay';

    private FakeClock $clock;

    private FakeHttpTransport $transport;

    private FakeCircuitBreaker $breaker;

    private RequestSigner $signer;

    private PayloadRedactor $redactor;

    private FakeCorrelationIdProvider $correlationIds;

    private ExternalApiCallerImpl $caller;

    // --- happy path ------------------------------------------------------

    #[Test]
    public function successful_call_returns_the_response_and_logs_headers(): void
    {
        $config = $this->makeConfig();
        $request = $this->makeRequest();

        $this->transport->queue(new ExternalResponse(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: ['status' => 'ok'],
            rawBody: '{"status":"ok"}',
            latencyMs: 17,
        ));

        $response = $this->caller->send($config, $request);

        self::assertSame(200, $response->status);
        self::assertSame(['status' => 'ok'], $response->body);
        self::assertSame(1, $this->transport->sendCallCount);
        self::assertSame(1, $this->breaker->successes);
        self::assertFalse($this->breaker->isOpen(ProviderCode::fromString(self::PROVIDER_CODE)));
    }

    #[Test]
    public function the_sent_request_carries_correlation_id_and_idempotency_key(): void
    {
        $config = $this->makeConfig();
        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: '/payments',
            providerCode: ProviderCode::fromString(self::PROVIDER_CODE),
            headers: [],
            body: ['amount' => 1],
            correlationId: 'caller-corr',
            idempotencyKey: 'idem-abc',
        );

        $this->transport->queue(new ExternalResponse(
            status: 202,
            headers: [],
            body: [],
            rawBody: '{}',
            latencyMs: 1,
        ));

        $this->caller->send($config, $request);

        $sent = $this->transport->sent[0];
        self::assertSame('caller-corr', $sent['headers']['Correlation-Id']);
        self::assertSame('idem-abc', $sent['headers']['Idempotency-Key']);
        self::assertSame('sk-test', $sent['headers']['X-Token']);
    }

    #[Test]
    public function correlation_id_falls_back_to_provider_when_caller_omits_it(): void
    {
        $config = $this->makeConfig();
        $request = $this->makeRequest(); // no correlationId

        $this->transport->queue(new ExternalResponse(
            status: 200, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));

        $this->caller->send($config, $request);

        self::assertSame('corr-test-1', $this->transport->sent[0]['headers']['Correlation-Id']);
    }

    #[Test]
    public function per_call_credentials_override_static_defaults(): void
    {
        $config = $this->makeConfig();
        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: '/payments',
            providerCode: ProviderCode::fromString(self::PROVIDER_CODE),
            headers: [],
            body: [],
            credentials: new Credentials(apiKey: 'sk-tenant-1'),
        );

        $this->transport->queue(new ExternalResponse(
            status: 200, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));

        $this->caller->send($config, $request);

        self::assertSame('sk-tenant-1', $this->transport->sent[0]['headers']['X-Token']);
    }

    // --- error classification ----------------------------------------------

    #[Test]
    public function a_4xx_other_than_401_403_is_permanent(): void
    {
        $config = $this->makeConfig();
        $request = $this->makeRequest();

        $this->transport->queue(new ExternalResponse(
            status: 422, headers: [], body: ['error' => 'invalid'], rawBody: '{}', latencyMs: 1,
        ));

        try {
            $this->caller->send($config, $request);
            self::fail('Expected ProviderPermanentError');
        } catch (ProviderPermanentError $e) {
            self::assertSame(422, $e->httpStatus);
        }
    }

    #[Test]
    public function a_401_or_403_is_an_auth_error(): void
    {
        $config = $this->makeConfig();
        $request = $this->makeRequest();

        $this->transport->queue(new ExternalResponse(
            status: 401, headers: [], body: [], rawBody: '{}', latencyMs: 1,
        ));

        $this->expectException(ProviderAuthError::class);
        $this->caller->send($config, $request);
    }

    #[Test]
    public function a_5xx_is_a_transient_error(): void
    {
        $config = $this->makeConfig();
        $request = $this->makeRequest();

        // 3 retries, all 503.
        $this->transport->queue(new ExternalResponse(
            status: 503, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));
        $this->transport->queue(new ExternalResponse(
            status: 503, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));
        $this->transport->queue(new ExternalResponse(
            status: 503, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));

        try {
            $this->caller->send($config, $request);
            self::fail('Expected ProviderTransientError after exhausted retries');
        } catch (ProviderTransientError $e) {
            self::assertSame(503, $e->httpStatus);
        }

        self::assertSame(3, $this->transport->sendCallCount);
    }

    #[Test]
    public function a_transient_5xx_is_retried_then_succeeds(): void
    {
        $config = $this->makeConfig(['max_attempts' => 3]);
        $request = $this->makeRequest();

        $this->transport->queue(new ExternalResponse(
            status: 502, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));
        $this->transport->queue(new ExternalResponse(
            status: 502, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));
        $this->transport->queue(new ExternalResponse(
            status: 200, headers: [], body: ['ok' => true], rawBody: '', latencyMs: 1,
        ));

        $response = $this->caller->send($config, $request);

        self::assertSame(200, $response->status);
        self::assertSame(3, $this->transport->sendCallCount);
    }

    #[Test]
    public function a_transport_failure_is_a_transient_error(): void
    {
        $config = $this->makeConfig(['max_attempts' => 2]);
        $request = $this->makeRequest();

        $this->transport->queue(new TransportExceptionStub(
            new ProviderTransientError(
                'connection refused',
                ProviderCode::fromString(self::PROVIDER_CODE),
            ),
        ));
        $this->transport->queue(new TransportExceptionStub(
            new ProviderTransientError(
                'connection refused',
                ProviderCode::fromString(self::PROVIDER_CODE),
            ),
        ));

        $this->expectException(ProviderTransientError::class);
        $this->caller->send($config, $request);
    }

    // --- guards ------------------------------------------------------------

    #[Test]
    public function a_disabled_provider_is_refused_with_no_http_call(): void
    {
        $data = [
            'code' => self::PROVIDER_CODE,
            'base_url' => 'https://api.example.com',
            'auth' => ['kind' => 'api-key'],
            'credentials' => ['api_key' => 'k'],
            'timeouts' => [],
            'retries' => [],
            'breaker' => [],
            'enabled' => false,
        ];
        $config = ProviderConfig::fromArray($data);

        $this->expectException(ProviderDisabledException::class);
        $this->caller->send($config, $this->makeRequest());

        self::assertSame(0, $this->transport->sendCallCount);
    }

    #[Test]
    public function an_open_breaker_refuses_calls(): void
    {
        $config = $this->makeConfig();
        $this->breaker->openFor(ProviderCode::fromString(self::PROVIDER_CODE));

        $this->expectException(CircuitOpenException::class);
        $this->caller->send($config, $this->makeRequest());

        self::assertSame(0, $this->transport->sendCallCount);
    }

    #[Test]
    public function provider_code_mismatch_is_a_logic_error(): void
    {
        $config = $this->makeConfig();

        $request = new ExternalRequest(
            method: HttpMethod::GET,
            path: '/x',
            providerCode: ProviderCode::fromString('someone-else'),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not match');
        $this->caller->send($config, $request);
    }

    // --- signing ------------------------------------------------------------

    #[Test]
    public function signing_adds_signature_and_timestamp_headers(): void
    {
        $this->clock->setTo(new DateTimeImmutable('2026-01-01T12:00:00Z'));
        $config = ProviderConfig::fromArray([
            'code' => self::PROVIDER_CODE,
            'base_url' => 'https://api.example.com',
            'auth' => ['kind' => 'api-key'],
            'credentials' => ['api_key' => 'k'],
            'timeouts' => [],
            'retries' => ['base_delay_ms' => 1, 'max_delay_ms' => 10],
            'breaker' => [],
            'signature' => [
                'algorithm' => 'hmac-sha256',
                'secret' => 's3cr3t',
                'signature_header' => 'X-Signature',
                'timestamp_header' => 'X-Timestamp',
            ],
        ]);
        $request = $this->makeRequest();

        $this->transport->queue(new ExternalResponse(
            status: 200, headers: [], body: [], rawBody: '', latencyMs: 1,
        ));

        $this->caller->send($config, $request);

        $sent = $this->transport->sent[0];
        self::assertArrayHasKey('X-Timestamp', $sent['headers']);
        self::assertArrayHasKey('X-Signature', $sent['headers']);
        self::assertNotEmpty($sent['headers']['X-Signature']);
        self::assertNotEmpty($sent['headers']['X-Timestamp']);
    }

    protected function setUp(): void
    {
        $this->clock = new FakeClock;
        $this->transport = new FakeHttpTransport;
        $this->breaker = new FakeCircuitBreaker;
        $this->signer = new HmacSha256RequestSigner;
        $this->redactor = new PayloadRedactor;
        $this->correlationIds = new FakeCorrelationIdProvider('corr-test-1');

        $this->caller = new ExternalApiCallerImpl(
            transport: $this->transport,
            circuitBreaker: $this->breaker,
            signer: $this->signer,
            redactor: $this->redactor,
            clock: $this->clock,
            logger: new NullLogger,
            authProviders: [new ApiKeyAuthProvider],
            correlationIds: $this->correlationIds,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): ProviderConfig
    {
        return ProviderConfig::fromArray([
            'code' => self::PROVIDER_CODE,
            'base_url' => 'https://api.example.com',
            'auth' => ['kind' => 'api-key', 'api_key_header' => 'X-Token'],
            'credentials' => ['api_key' => 'sk-test'],
            'timeouts' => ['request' => 5.0],
            'retries' => [
                'max_attempts' => $overrides['max_attempts'] ?? 3,
                'base_delay_ms' => 1, // keep tests fast
                'max_delay_ms' => 10,
                'retryable_statuses' => [502, 503, 504],
            ],
            'breaker' => [
                'failure_threshold' => $overrides['failure_threshold'] ?? 5,
            ],
            'extras' => [],
        ]);
    }

    private function makeRequest(): ExternalRequest
    {
        return new ExternalRequest(
            method: HttpMethod::POST,
            path: '/payments',
            providerCode: ProviderCode::fromString(self::PROVIDER_CODE),
            headers: ['Accept' => 'application/json'],
            body: ['amount' => 100],
        );
    }
}
