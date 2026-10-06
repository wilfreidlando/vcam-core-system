<?php

declare(strict_types=1);

namespace Core\Support\Integration\Telemetry;

use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Transport\HttpTransport;
use Core\Support\Observability\Tracing\Span;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ships finished spans to an OpenTelemetry collector as OTLP/HTTP JSON
 * (`POST {endpoint}/v1/traces`) — Grafana Alloy forwards them to Tempo
 * (ADR-0059). Lives under `Core\Support\Integration` because it opens an
 * outbound HTTP connection (G6c: only this namespace may).
 *
 * Never throws: telemetry failing must never fail a payment. A failed
 * export is logged once at warning level and the spans are dropped.
 */
final readonly class OtlpHttpSpanExporter
{
    public function __construct(
        private HttpTransport $transport,
        private LoggerInterface $logger,
        private string $endpoint,
        private string $serviceName,
        private float $timeoutSeconds = 1.0,
    ) {}

    /**
     * @param list<Span> $spans
     */
    public function export(array $spans): void
    {
        if ($spans === [] || $this->endpoint === '') {
            return;
        }

        try {
            $response = $this->transport->send(
                method: HttpMethod::POST,
                url: rtrim($this->endpoint, '/').'/v1/traces',
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($this->payload($spans), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                timeoutSeconds: $this->timeoutSeconds,
            );

            if ($response->status >= 300) {
                $this->logger->warning('telemetry.export.rejected', ['status' => $response->status, 'spans' => count($spans)]);
            }
        } catch (Throwable $e) {
            $this->logger->warning('telemetry.export.failed', ['error' => $e->getMessage(), 'spans' => count($spans)]);
        }
    }

    /**
     * @param list<Span> $spans
     * @return array<string, mixed>
     */
    public function payload(array $spans): array
    {
        return [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [
                        self::attribute('service.name', $this->serviceName),
                    ],
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => 'core-system'],
                    'spans' => array_map(fn (Span $span): array => $this->span($span), $spans),
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function span(Span $span): array
    {
        $attributes = [];
        foreach ($span->attributes() as $key => $value) {
            if ($value !== null) {
                $attributes[] = self::attribute($key, $value);
            }
        }

        $encoded = [
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'name' => $span->name,
            'kind' => $span->kind->value,
            'startTimeUnixNano' => (string) $span->startUnixNano,
            'endTimeUnixNano' => (string) $span->endUnixNano(),
            'attributes' => $attributes,
            'status' => $span->isError()
                ? ['code' => 2, 'message' => (string) $span->statusMessage()]
                : ['code' => 1],
        ];

        if ($span->parentSpanId !== null) {
            $encoded['parentSpanId'] = $span->parentSpanId;
        }

        return $encoded;
    }

    /**
     * @return array{key: string, value: array<string, scalar>}
     */
    private static function attribute(string $key, string|int|float|bool $value): array
    {
        return [
            'key' => $key,
            'value' => match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                default => ['stringValue' => $value],
            },
        ];
    }
}
