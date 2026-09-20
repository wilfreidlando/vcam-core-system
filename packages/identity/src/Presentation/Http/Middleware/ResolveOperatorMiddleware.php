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
 *
 * Token source, in priority order:
 *
 *  1. `Authorization: Bearer <token>` header — the canonical API path,
 *     used by external integrators and by the web console's AJAX-free
 *     flows.
 *  2. The operator session (see SESSION_KEY) — the encrypted server-side
 *     slot `App\Http\Controllers\Admin\AuthController::completeLogin()`
 *     writes after a successful login+MFA. Lets a browser-authenticated
 *     operator hit `/v1/admin/*` URLs (the Scramble docs UI, links
 *     shared in chat, manual address-bar navigation, etc.) without
 *     manually pasting a Bearer token. An upstream `operator.session`
 *     middleware on the web plane has already resolved the context in
 *     this case — the bound check at the top short-circuits before any
 *     token lookup.
 *  3. Otherwise: 401 problem+json for an API caller
 *     (`Accept: application/json`), or a redirect to `/admin/login` for
 *     a browser navigation. The redirect is what the operator sees as
 *     "send me to the login page" instead of a raw JSON body.
 */
final class ResolveOperatorMiddleware
{
    /**
     * Session key shared with `App\Http\Middleware\ResolveOperatorSession`
     * and `App\Http\Controllers\Admin\AuthController::completeLogin()`.
     * The same encrypted server-side slot carries the plaintext operator
     * bearer token across HTTP requests in the web console.
     */
    public const SESSION_KEY = 'operator_bearer_token';

    public function __construct(
        private readonly ResolveOperatorFromBearerToken $resolveOperator,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Short-circuit when an upstream middleware on this same request
        // has already resolved the operator (the web plane's
        // `operator.session`, or another middleware on the chain that
        // understands the operator auth model). Keyed off the Symfony
        // request attribute bag — per-request, never visible to the next
        // HTTP request in a test or worker that reuses the container.
        if ($request->attributes->has(OperatorContext::class)) {
            return $next($request);
        }

        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            // No Bearer header — fall back to the encrypted operator
            // session if one is attached to this request. A session is
            // only attached after `Illuminate\Session\Middleware\StartSession`
            // has run, which the `/v1/admin/*` route group wires in
            // explicitly; for pure API requests (e.g. `/v1/operators/me`
            // from an external integrator) there's no session at all and
            // `$request->session()` would throw. `hasSession()` is the
            // Laravel-blessed way to ask "is there a session on this
            // request?" without an exception.
            if ($request->hasSession()) {
                $sessionToken = $request->session()->get(self::SESSION_KEY);
                if (is_string($sessionToken) && $sessionToken !== '') {
                    $token = $sessionToken;
                }
            }
        }

        if (! is_string($token) || $token === '') {
            return $this->unauthenticated($request);
        }

        try {
            $context = ($this->resolveOperator)($token);
        } catch (InvalidOperatorCredentials) {
            return $this->unauthenticated($request, 'The provided token is invalid or has expired.');
        } catch (OperatorRevoked) {
            return $this->forbidden($request, 'This operator has been revoked.');
        }

        app()->instance(OperatorContext::class, $context);
        $request->attributes->set(OperatorContext::class, $context);

        return $next($request);
    }

    private function unauthenticated(Request $request, string $detail = 'A Bearer operator token is required.'): Response
    {
        if ($request->expectsJson()) {
            return ProblemDetails::unauthenticated($detail);
        }

        // Browser navigation: clear the stale token and send the operator
        // to the login page rather than rendering a JSON body they can't
        // act on.
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return redirect()->route('admin.login.show')
            ->withErrors(['email' => 'Please sign in to continue.']);
    }

    private function forbidden(Request $request, string $detail): Response
    {
        if ($request->expectsJson()) {
            return ProblemDetails::forbidden($detail);
        }

        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return redirect()->route('admin.login.show')
            ->withErrors(['email' => $detail]);
    }
}
