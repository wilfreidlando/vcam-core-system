<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Middleware;

use Closure;
use Core\Identity\Application\UseCase\ResolveOperatorFromBearerToken;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator authentication path (spec technique §5.1, Plan 1) — every
 * `admin` route runs through this before it runs its own logic. Mirrors
 * `ResolveProjectMiddleware` for the machine-identity plane.
 */
final class ResolveOperatorMiddleware
{
    public function __construct(
        private readonly ResolveOperatorFromBearerToken $resolveOperator,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return ProblemDetails::unauthenticated('A Bearer operator token is required.');
        }

        try {
            $context = ($this->resolveOperator)($token);
        } catch (InvalidOperatorCredentials) {
            return ProblemDetails::unauthenticated('The provided token is invalid or has expired.');
        } catch (OperatorRevoked) {
            return ProblemDetails::forbidden('This operator has been revoked.');
        }

        app()->instance(OperatorContext::class, $context);

        return $next($request);
    }
}
