<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ADR-0059 — request counters and latency histogram, aggregated across
 * every web/worker process in Valkey and rendered by `/metrics`.
 * Labels: method, route NAME (never the raw path — bounded cardinality),
 * status. A metrics write failing must never fail a request.
 */
final class RecordHttpMetrics
{
    public const HASH_KEY = 'core:metrics:http';

    /** Prometheus histogram upper bounds, seconds. */
    public const BUCKETS = [0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = $next($request);

        if ($request->is('metrics', 'up', 'ready')) {
            return $response;
        }

        $seconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $method = $request->getMethod();
        $route = $request->route()?->getName() ?? 'unmatched';
        $status = (string) $response->getStatusCode();

        try {
            $redis = Redis::connection();
            $redis->command('hincrby', [self::HASH_KEY, "count|{$method}|{$route}|{$status}", 1]);
            $redis->command('hincrbyfloat', [self::HASH_KEY, "sum|{$method}|{$route}", $seconds]);
            foreach (self::BUCKETS as $le) {
                if ($seconds <= $le) {
                    $redis->command('hincrby', [self::HASH_KEY, "bucket|{$method}|{$route}|{$le}", 1]);
                }
            }
            $redis->command('hincrby', [self::HASH_KEY, "bucket|{$method}|{$route}|+Inf", 1]);
        } catch (Throwable) {
            // Metrics are best-effort.
        }

        return $response;
    }
}
