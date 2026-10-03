<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Core\Identity\Application\UseCase\ResolveOperatorFromBearerToken;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Session;

/**
 * Session-based auth for the admin web UI (review finding, Lot 6
 * follow-up — replaces an earlier Sanctum-stateful-cookie attempt that
 * could not work: Sanctum never re-exposes a token's plaintext after
 * issuance, `Sanctum::currentAccessToken()` isn't a static method, the
 * `web` guard pointed at the wrong model, and the stateful middleware
 * was never registered — four independent reasons the previous version
 * fatally errored on every authenticated request).
 *
 * Reads the plaintext bearer token `AuthController::completeLogin()`
 * put in the server-side session, and resolves it through the exact
 * same `ResolveOperatorFromBearerToken` use case the API's
 * `operator.auth` middleware uses for the `Authorization` header — one
 * authentication code path for both planes.
 *
 * On success, `OperatorContext` is bound as a per-request instance
 * singleton so controllers can type-hint it.
 *
 * On failure: redirect to the login page with a flashed error — unlike
 * `operator.auth` (API, returns RFC 7807 JSON), this middleware only
 * ever serves browser navigation, so a redirect is the correct answer,
 * not a JSON body a human would have to read raw.
 */
final class ResolveOperatorSession
{
    private const SESSION_KEY = ResolveOperatorMiddleware::SESSION_KEY;

    public function __construct(
        private readonly ResolveOperatorFromBearerToken $resolve,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $token = Session::get(self::SESSION_KEY);
        if (! is_string($token) || $token === '') {
            return $this->redirectToLogin('Please sign in to continue.');
        }

        try {
            $context = ($this->resolve)($token);
        } catch (InvalidOperatorCredentials) {
            Session::forget(self::SESSION_KEY);

            return $this->redirectToLogin('Your session has expired. Please sign in again.');
        } catch (OperatorRevoked) {
            Session::forget(self::SESSION_KEY);

            return $this->redirectToLogin('Your operator access has been revoked.');
        }

        // Bind the context for the duration of this request. Controllers
        // can type-hint OperatorContext and receive it via DI.
        app()->instance(OperatorContext::class, $context);
        Context::add('operator_id', $context->operatorId);

        return $next($request);
    }

    private function redirectToLogin(string $error): RedirectResponse
    {
        return redirect()->route('admin.login.show')->withErrors(['email' => $error]);
    }
}
