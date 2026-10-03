<?php

declare(strict_types=1);

namespace Core\Support\Http\Middleware;

use Closure;
use Core\Support\Observability\TraceContext;
use Core\Support\Observability\Tracing\SpanKind;
use Core\Support\Observability\Tracing\Tracer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ADR-0059 — the root SERVER span of a request. Every span opened while
 * the request runs (SQL, provider calls) and every job it dispatches
 * (Laravel carries the Context, hence the parent span id, into the job)
 * becomes its descendant in Tempo.
 */
final readonly class TraceHttpRequest
{
    public function __construct(
        private Tracer $tracer,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $span = $this->tracer->start($request->getMethod().' /'.ltrim($request->path(), '/'), SpanKind::Server, [
            'http.request.method' => $request->getMethod(),
            'url.path' => '/'.ltrim($request->path(), '/'),
            'client.address' => $request->ip(),
        ]);

        $previousParent = TraceContext::parentSpanId();
        TraceContext::setParentSpanId($span->spanId);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->tracer->end($span->markError($e::class));
            TraceContext::setParentSpanId($previousParent);

            throw $e;
        }

        $route = $request->route()?->getName();
        if ($route !== null) {
            $span->setAttribute('http.route', $route);
        }

        $status = $response->getStatusCode();
        $span->setAttribute('http.response.status_code', $status);
        if ($status >= 500) {
            $span->markError('HTTP '.$status);
        }

        $this->tracer->end($span);
        TraceContext::setParentSpanId($previousParent);

        return $response;
    }
}
