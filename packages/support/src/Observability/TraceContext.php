<?php

declare(strict_types=1);

namespace Core\Support\Observability;

use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The two identifiers every log line, span, outbox row and timeline entry
 * carries (ADR-0059):
 *
 *  - `correlation_id` — the business thread. Returned to the caller in the
 *    `Correlation-Id` response header; what an integrator quotes to support.
 *  - `trace_id` — W3C Trace Context id (32 lowercase hex). Groups every
 *    span of the request AND of the jobs it later causes (webhook
 *    deliveries, payment callbacks) into one trace in Tempo.
 *
 * Both live in Laravel's `Context`, which Laravel appends to every log
 * record and carries into queued jobs automatically — so a delivery job
 * that runs three minutes later still logs with the request's ids.
 */
final class TraceContext
{
    public const CORRELATION_KEY = 'correlation_id';

    public const TRACE_KEY = 'trace_id';

    public const PARENT_SPAN_KEY = 'parent_span_id';

    /**
     * Accepted inbound correlation id: a UUID or up to 64 safe characters.
     * Anything else (control characters, JSON fragments, megabyte strings)
     * is replaced — an inbound header must never be able to forge log
     * lines (audit 2026-10-03).
     */
    private const SAFE_CORRELATION_ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    public static function sanitizeCorrelationId(mixed $candidate): string
    {
        return is_string($candidate) && preg_match(self::SAFE_CORRELATION_ID, $candidate) === 1
            ? $candidate
            : (string) Str::uuid();
    }

    /**
     * Parses a W3C `traceparent` header (`00-<trace-id>-<parent-id>-<flags>`).
     *
     * @return array{trace_id: string, parent_span_id: string}|null
     */
    public static function parseTraceparent(mixed $header): ?array
    {
        if (! is_string($header) || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $header, $m) !== 1) {
            return null;
        }

        if ($m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return null;
        }

        return ['trace_id' => $m[1], 'parent_span_id' => $m[2]];
    }

    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function begin(string $correlationId, ?string $traceId = null, ?string $parentSpanId = null): void
    {
        Context::add(self::CORRELATION_KEY, $correlationId);
        Context::add(self::TRACE_KEY, $traceId ?? self::newTraceId());

        if ($parentSpanId !== null) {
            Context::addHidden(self::PARENT_SPAN_KEY, $parentSpanId);
        } else {
            Context::forgetHidden(self::PARENT_SPAN_KEY);
        }
    }

    public static function correlationId(): ?string
    {
        if (! self::available()) {
            return null;
        }

        $value = Context::get(self::CORRELATION_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function traceId(): ?string
    {
        if (! self::available()) {
            return null;
        }

        $value = Context::get(self::TRACE_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function parentSpanId(): ?string
    {
        if (! self::available()) {
            return null;
        }

        $value = Context::getHidden(self::PARENT_SPAN_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function setParentSpanId(?string $spanId): void
    {
        if (! self::available()) {
            return;
        }

        if ($spanId === null) {
            Context::forgetHidden(self::PARENT_SPAN_KEY);

            return;
        }

        Context::addHidden(self::PARENT_SPAN_KEY, $spanId);
    }

    /**
     * `traceparent` header for an outbound call made inside the current
     * trace, so a provider or receiver that speaks W3C Trace Context can
     * join it.
     */
    public static function traceparent(string $spanId): ?string
    {
        $traceId = self::traceId();

        return $traceId === null ? null : sprintf('00-%s-%s-01', $traceId, $spanId);
    }

    /**
     * False outside a booted application (pure unit tests): every accessor
     * then answers "no current trace" instead of throwing.
     */
    private static function available(): bool
    {
        $app = Context::getFacadeApplication();

        return $app !== null && $app->bound(Repository::class);
    }
}
