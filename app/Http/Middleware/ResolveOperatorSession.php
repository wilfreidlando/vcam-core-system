<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Core\Identity\Application\UseCase\ResolveOperatorFromBearerToken;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

/**
 * Cookie-based auth for the admin web UI.
 *
 * Reads the Sanctum token from the `web` guard's cookie (set after a
 * successful login + MFA flow on the web plane), resolves it through
 * the existing `ResolveOperatorFromBearerToken` use case — the same
 * path the API's `operator.auth` middleware uses for Bearer tokens.
 * The two middlewares share their authentication logic — there is no
 * duplication of the timing-equalized login or the MFA flow.
 *
 * On success, `OperatorContext` is bound as a per-request instance
 * singleton so controllers can type-hint it.
 *
 * On failure: same problem+json shape as the API (401 unauthenticated,
 * 403 forbidden). The web layer converts to its own redirect semantics
 * inside the AuthController (the middleware itself stays
 * problem-format-agnostic — a JSON 401 is the same answer regardless
 * of whether the caller is a SPA, a curl, or a browser).
 */
final class ResolveOperatorSession
{
    public function __construct(
        private readonly ResolveOperatorFromBearerToken $resolve,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // Sanctum exposes `currentAccessToken()` only when the request
        // is stateful (web group, cookie set) AND a valid cookie is
        // present. On the API plane (bearer), this returns null — the
        // operator.auth middleware handles that case.
        $token = Sanctum::currentAccessToken();
        if ($token === null) {
            return ProblemDetails::unauthenticated('Operator session cookie is required.');
        }

        try {
            /** @var OperatorContext $context */
            $context = ($this->resolve)($token);
        } catch (InvalidOperatorCredentials $e) {
            return ProblemDetails::unauthenticated($e->getMessage());
        } catch (OperatorRevoked $e) {
            return ProblemDetails::forbidden($e->getMessage());
        }

        // Bind the context for the duration of this request. Controllers
        // can type-hint OperatorContext and receive it via DI.
        app()->instance(OperatorContext::class, $context);

        return $next($request);
    }
}
