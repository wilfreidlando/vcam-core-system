<?php

declare(strict_types=1);

namespace Core\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts an inbound Correlation-Id, generates one when absent, exposes it to
 * every log record (Laravel appends the Context bag to logs automatically) and
 * echoes it back on the response.
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
        $inbound = $request->header(self::HEADER);

        $correlationId = is_string($inbound) && $inbound !== ''
            ? $inbound
            : (string) Str::uuid();

        Context::add('correlation_id', $correlationId);
        $request->headers->set(self::HEADER, $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
