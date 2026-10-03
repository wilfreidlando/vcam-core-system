<?php

declare(strict_types=1);

namespace Core\Support\Observability\Tracing;

use Core\Support\Observability\TraceContext;

/**
 * Minimal in-process tracer (ADR-0059): collects the spans of the current
 * request or job, which `OtlpHttpSpanExporter` ships to the collector once
 * the response is sent (never on the request's critical path).
 *
 * Deliberately not the OpenTelemetry SDK: auto-instrumentation needs a
 * PECL extension the image does not carry, and the four instrumentation
 * points the Core needs (HTTP server, SQL, outbound HTTP, queued jobs)
 * fit in a few dozen lines. The wire format is standard OTLP/JSON, so
 * moving to the SDK later changes no dashboard and no stored trace.
 *
 * A disabled tracer (no collector configured — tests, local) still hands
 * out spans (callers stay branch-free) but never keeps them.
 */
final class Tracer
{
    /** Bounded buffer — a long job must not grow memory unbounded. */
    private const MAX_BUFFERED_SPANS = 2_000;

    /** @var list<Span> */
    private array $finished = [];

    public function __construct(
        private readonly bool $enabled = false,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Starts a span in the current trace, child of the current parent span
     * (or of `$parentSpanId` when given). Does not change the current
     * parent — use `TraceContext::setParentSpanId()` for spans that wrap
     * other work.
     *
     * @param array<string, scalar|null> $attributes
     */
    public function start(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?string $parentSpanId = null, ?int $startUnixNano = null): Span
    {
        $traceId = TraceContext::traceId() ?? TraceContext::newTraceId();

        $span = new Span(
            traceId: $traceId,
            spanId: TraceContext::newSpanId(),
            parentSpanId: $parentSpanId ?? TraceContext::parentSpanId(),
            name: $name,
            kind: $kind,
            startUnixNano: $startUnixNano ?? self::nowUnixNano(),
            attributes: $attributes,
        );

        $correlationId = TraceContext::correlationId();
        if ($correlationId !== null) {
            $span->setAttribute('correlation_id', $correlationId);
        }

        return $span;
    }

    public function end(Span $span, ?int $endUnixNano = null): void
    {
        $span->finish($endUnixNano ?? self::nowUnixNano());

        if (! $this->enabled) {
            return;
        }

        $this->finished[] = $span;

        if (count($this->finished) > self::MAX_BUFFERED_SPANS) {
            $this->finished = array_slice($this->finished, -self::MAX_BUFFERED_SPANS);
        }
    }

    /**
     * @return list<Span>
     */
    public function flush(): array
    {
        $spans = $this->finished;
        $this->finished = [];

        return $spans;
    }

    public static function nowUnixNano(): int
    {
        return (int) round(microtime(true) * 1_000_000) * 1_000;
    }
}
