<?php

declare(strict_types=1);

namespace Core\Support\Http\Middleware;

use Closure;
use Core\Support\Http\ProblemDetails;
use Core\Support\Idempotency\IdempotencyKeyAlreadyLocked;
use Core\Support\Idempotency\IdempotencyScope;
use Core\Support\Idempotency\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTTP-level idempotency guard ADR-0025 designs and G7 requires on
 * every monetary mutation route. Scoped by `(IdempotencyScope::current(),
 * Idempotency-Key)` — in practice a Project id, so two Projects may
 * reuse the same key with no collision.
 *
 * State machine (ADR-0025):
 *   - header absent               -> 400 `idempotency-key-required`
 *   - unknown key                 -> lock it, run the route, cache the
 *                                     response, release
 *   - known, same request, done   -> replay the cached response verbatim,
 *                                     the route handler never runs again
 *   - known, different request    -> 409 `idempotency-key-conflict` (the
 *                                     key was reused for a different body)
 *   - known, still in flight      -> 409 (a concurrent request holds the
 *                                     lock; racing it would risk a double
 *                                     effect, not just a duplicate row)
 *
 * Runs after `project.auth` in the route's middleware stack — it needs
 * `ProjectContext` already bound so `IdempotencyScope::current()` (its
 * app-shell implementation) can read it.
 */
final class RequireIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly IdempotencyScope $scope,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            return ProblemDetails::make(
                'idempotency-key-required',
                'Idempotency-Key header is required',
                400,
                'This route mutates money and must be called with an Idempotency-Key header.',
            );
        }

        $scope = $this->scope->current();
        $requestHash = hash('sha256', $request->getContent());
        $existing = $this->store->find($scope, $key);

        if ($existing !== null) {
            if (! $existing->completed) {
                return ProblemDetails::make(
                    'idempotency-key-conflict',
                    'A request with this Idempotency-Key is already in progress',
                    409,
                    'Wait for the original request to complete before retrying.',
                );
            }

            if ($existing->requestHash !== $requestHash) {
                return ProblemDetails::make(
                    'idempotency-key-conflict',
                    'Idempotency-Key was reused for a different request',
                    409,
                    'This key was already used with a different request body.',
                );
            }

            // Same key, same body, already completed: replay verbatim.
            // The route handler never runs — no second effect.
            return new JsonResponse($existing->responseBody, $existing->responseStatus ?? 200);
        }

        try {
            $this->store->lock($scope, $key, $requestHash);
        } catch (IdempotencyKeyAlreadyLocked) {
            // Lost a race against a concurrent request for the same key.
            return ProblemDetails::make(
                'idempotency-key-conflict',
                'A request with this Idempotency-Key is already in progress',
                409,
                'Wait for the original request to complete before retrying.',
            );
        }

        $response = $next($request);

        $content = $response->getContent();
        $decoded = json_decode($content !== false && $content !== '' ? $content : '{}', true);
        /** @var array<string, mixed> $body */
        $body = is_array($decoded) ? $decoded : [];
        $this->store->complete($scope, $key, $response->getStatusCode(), $body);

        return $response;
    }
}
