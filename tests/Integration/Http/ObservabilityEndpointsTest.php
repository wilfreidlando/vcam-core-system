<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\TestCase;

/**
 * ADR-0059 — probes, metrics, request/response logging, correlation echo.
 */
final class ObservabilityEndpointsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function readiness_reports_each_dependency(): void
    {
        $response = $this->getJson('/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.outbox_relay', true);
    }

    #[Test]
    public function metrics_are_refused_to_a_public_caller_without_the_token(): void
    {
        config(['observability.metrics.token' => '']);

        $this->call('GET', '/metrics', server: ['REMOTE_ADDR' => '41.202.219.70'])->assertForbidden();
    }

    #[Test]
    public function metrics_are_served_with_the_token_in_prometheus_format(): void
    {
        config(['observability.metrics.token' => 'scrape-secret']);

        $this->call('GET', '/metrics', server: ['REMOTE_ADDR' => '41.202.219.70'])->assertForbidden();

        $response = $this->call('GET', '/metrics', server: [
            'REMOTE_ADDR' => '41.202.219.70',
            'HTTP_AUTHORIZATION' => 'Bearer scrape-secret',
        ]);

        $response->assertOk();
        $body = (string) $response->baseResponse->getContent();
        self::assertStringContainsString('# TYPE core_up gauge', $body);
        self::assertStringContainsString('core_outbox_unpublished 0', $body);
        self::assertStringContainsString('# TYPE core_payments gauge', $body);
    }

    #[Test]
    public function every_response_echoes_a_correlation_id_and_rejects_a_forged_one(): void
    {
        $this->getJson('/', ['Correlation-Id' => 'client-ref-42'])
            ->assertHeader('Correlation-Id', 'client-ref-42');

        $forged = $this->getJson('/', ['Correlation-Id' => "x\n{\"level\":\"critical\"}"]);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $forged->headers->get('Correlation-Id'));
    }

    #[Test]
    public function request_and_response_are_logged_with_secrets_redacted(): void
    {
        $logger = $this->recordingLogger();

        $this->app->instance(LoggerInterface::class, $logger);

        $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/x', 'secret' => 'do-not-log'], [
            'Authorization' => 'Bearer pk_invalid.secretpart',
            'Correlation-Id' => 'corr-log-test',
        ]);

        $received = array_values(array_filter($logger->records, static fn (array $r): bool => $r['message'] === 'http.request.received'));
        $sent = array_values(array_filter($logger->records, static fn (array $r): bool => $r['message'] === 'http.response.sent'));

        self::assertCount(1, $received);
        self::assertCount(1, $sent);

        $encoded = (string) json_encode($received[0]['context'], JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('do-not-log', $encoded);
        self::assertStringNotContainsString('secretpart', $encoded);
        self::assertStringContainsString('/v1/webhook-endpoints', $encoded);

        self::assertSame('warning', $sent[0]['level']);
        self::assertStringContainsString('"status":401', (string) json_encode($sent[0]['context']));
    }

    #[Test]
    public function a_scanner_probing_an_unknown_path_is_logged_at_info_not_as_a_warning(): void
    {
        $logger = $this->recordingLogger();
        $this->app->instance(LoggerInterface::class, $logger);

        $this->getJson('/aws/.env')->assertNotFound();

        $sent = array_values(array_filter($logger->records, static fn (array $r): bool => $r['message'] === 'http.response.sent'));

        self::assertCount(1, $sent, 'the exchange is still logged, so the probe stays searchable by path');
        self::assertSame('info', $sent[0]['level']);
        self::assertStringContainsString('"status":404', (string) json_encode($sent[0]['context']));
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<mixed>}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
