<?php

declare(strict_types=1);

use Core\Support\Integration\ExternalResponse;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Telemetry\OtlpHttpSpanExporter;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Support\Observability\Tracing\SpanKind;
use Core\Support\Observability\Tracing\Tracer;
use Psr\Log\NullLogger;

/**
 * ADR-0059 — spans are kept only when a collector is configured, and are
 * shipped as standard OTLP/JSON.
 */
final class RecordingOtlpTransport implements HttpTransport
{
    /** @var list<array{url: string, body: string|array<mixed>|null}> */
    public array $calls = [];

    public function send(HttpMethod $method, string $url, array $headers = [], string|array|null $body = null, ?float $timeoutSeconds = null): ExternalResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $body];

        return new ExternalResponse(status: 200);
    }
}

it('a disabled tracer never buffers spans', function (): void {
    $tracer = new Tracer(enabled: false);
    $tracer->end($tracer->start('noop'));

    expect($tracer->flush())->toBe([]);
});

it('exports finished spans as OTLP JSON to /v1/traces', function (): void {
    $tracer = new Tracer(enabled: true);
    $span = $tracer->start('POST /v1/payments', SpanKind::Server, ['http.request.method' => 'POST'], parentSpanId: 'ffffffffffffffff');
    $span->setAttribute('http.response.status_code', 201);
    $tracer->end($span);

    $transport = new RecordingOtlpTransport;
    (new OtlpHttpSpanExporter($transport, new NullLogger, 'http://alloy:4318', 'core-system'))->export($tracer->flush());

    expect($transport->calls)->toHaveCount(1)
        ->and($transport->calls[0]['url'])->toBe('http://alloy:4318/v1/traces');

    $body = $transport->calls[0]['body'];
    /** @var array{resourceSpans: list<array{resource: array{attributes: list<mixed>}, scopeSpans: list<array{spans: list<array<string, mixed>>}>}>} $payload */
    $payload = json_decode(is_string($body) ? $body : '', true);
    $encoded = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

    expect($payload['resourceSpans'][0]['resource']['attributes'][0])->toBe(['key' => 'service.name', 'value' => ['stringValue' => 'core-system']])
        ->and($encoded['name'])->toBe('POST /v1/payments')
        ->and($encoded['kind'])->toBe(2)
        ->and($encoded['parentSpanId'])->toBe('ffffffffffffffff')
        ->and($encoded['traceId'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($encoded['attributes'])->toContain(['key' => 'http.response.status_code', 'value' => ['intValue' => '201']]);
});

it('never exports when no collector endpoint is configured', function (): void {
    $transport = new RecordingOtlpTransport;
    $tracer = new Tracer(enabled: true);
    $tracer->end($tracer->start('x'));

    (new OtlpHttpSpanExporter($transport, new NullLogger, '', 'core-system'))->export($tracer->flush());

    expect($transport->calls)->toBe([]);
});
