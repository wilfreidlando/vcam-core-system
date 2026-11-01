<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Core\Identity\Contracts\ProjectContext;
use Core\Identity\Presentation\Http\Middleware\ResolveProjectMiddleware;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `project.auth` as the composition root binds it: the machine-identity
 * authentication, then ADR-0085 §1's fairness limits — per project, per API
 * key, and per costly route class.
 *
 * Limiting happens after authentication because the budget belongs to the
 * project, not to an address. The ceiling per IP of the `api` limiter still
 * runs before it and bounds unauthenticated traffic.
 *
 * **Fails open** (ADR-0085 §1): if the counter store is unavailable the
 * request goes through and a warning is logged — the protection must not
 * become an outage.
 */
final class AuthenticateProjectWithRateLimit
{
    /** Methods that do not mutate: only the project and key budgets apply. */
    private const READS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ResolveProjectMiddleware $authenticate,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->authenticate->handle($request, function (Request $request) use ($next): Response {
            if (config('ratelimit.enabled') !== true) {
                return $next($request);
            }

            $context = app(ProjectContext::class);

            try {
                $verdict = $this->check($request, $context);
            } catch (Throwable $e) {
                Log::warning('Rate limiter unavailable: the request goes through.', ['project_id' => $context->projectId, 'error' => $e::class]);

                return $next($request);
            }

            if ($verdict['retry_after'] !== null) {
                return $this->tooMany($verdict);
            }

            $response = $next($request);
            $this->headers($response, $verdict);

            return $response;
        });
    }

    /**
     * @return array{limit: int, remaining: int, reset: int, retry_after: int|null}
     */
    private function check(Request $request, ProjectContext $context): array
    {
        $overrides = config('ratelimit.projects.'.$context->projectId);
        $overrides = is_array($overrides) ? $overrides : [];

        $buckets = [
            ['rl:p:'.$context->projectId, $this->int($overrides['project_per_minute'] ?? config('ratelimit.project_per_minute'), 3000)],
            ['rl:k:'.$context->apiKeyId, $this->int($overrides['key_per_minute'] ?? config('ratelimit.key_per_minute'), 1500)],
        ];

        if (! in_array($request->method(), self::READS, true)) {
            $classes = config('ratelimit.classes');

            foreach (is_array($classes) ? $classes : [] as $permission => $budget) {
                if (is_string($permission) && $this->routeIsOfClass($request, $permission)) {
                    $buckets[] = ['rl:c:'.$context->projectId.':'.$permission, $this->int($budget, 600)];
                }
            }
        }

        // The tightest bucket (least remaining) is the one the caller is told about.
        $tightest = ['limit' => 0, 'remaining' => PHP_INT_MAX, 'reset' => 0, 'retry_after' => null];

        foreach ($buckets as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $wait = max(1, RateLimiter::availableIn($key));

                return ['limit' => $limit, 'remaining' => 0, 'reset' => $wait, 'retry_after' => $wait];
            }

            RateLimiter::hit($key, 60);
            $remaining = max(0, RateLimiter::remaining($key, $limit));

            if ($remaining < $tightest['remaining']) {
                $tightest = ['limit' => $limit, 'remaining' => $remaining, 'reset' => max(1, RateLimiter::availableIn($key)), 'retry_after' => null];
            }
        }

        return $tightest;
    }

    private function routeIsOfClass(Request $request, string $permission): bool
    {
        $path = $request->path();

        return match ($permission) {
            'payments:write' => str_starts_with($path, 'v1/payments') || (str_starts_with($path, 'v1/invoices/') && str_ends_with($path, '/pay')),
            'notifications:write' => str_starts_with($path, 'v1/notifications'),
            default => false,
        };
    }

    /**
     * @param array{limit: int, remaining: int, reset: int, retry_after: int|null} $verdict
     */
    private function tooMany(array $verdict): Response
    {
        $response = ProblemDetails::make('rate-limit-exceeded', 'Rate limit exceeded', 429, 'Too many requests for this project. Retry after the delay in Retry-After.');
        $this->headers($response, $verdict);
        $response->headers->set('Retry-After', (string) ($verdict['retry_after'] ?? 1));

        return $response;
    }

    /**
     * @param array{limit: int, remaining: int, reset: int, retry_after: int|null} $verdict
     */
    private function headers(Response $response, array $verdict): void
    {
        $response->headers->set('RateLimit-Limit', (string) $verdict['limit']);
        $response->headers->set('RateLimit-Remaining', (string) $verdict['remaining']);
        $response->headers->set('RateLimit-Reset', (string) $verdict['reset']);
    }

    private function int(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }
}
