<?php

declare(strict_types=1);

namespace Core\Support\Http\Middleware;

use Closure;
use Core\Support\Observability\TraceContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts an inbound Correlation-Id (validated — see `TraceContext`), generates
 * one when absent, joins an inbound W3C `traceparent` or starts a new trace,
 * exposes both ids to every log record (Laravel appends the Context bag to
 * logs automatically) and echoes the correlation id back on the response.
 *
 * This is the thread that makes an incident investigable: one identifier links
 * the consumer's request, the provider call, the ledger entries, the outbox
 * event, the webhook delivery and the audit trail.
 */
final class CorrelationId
{
    public const HEADER = 'Correlation-Id';

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = TraceContext::sanitizeCorrelationId($request->header(self::HEADER));
        $traceparent = TraceContext::parseTraceparent($request->header('traceparent'));

        TraceContext::begin($correlationId, $traceparent['trace_id'] ?? null, $traceparent['parent_span_id'] ?? null);
        $request->headers->set(self::HEADER, $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
