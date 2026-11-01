<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Middleware;

use Closure;
use Core\Portal\Application\UseCase\PortalSessionService;
use Core\Portal\Presentation\Http\PortalCookie;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0075 §Sécurité — the cookie decides who the request is; for every
 * state-changing request the anti-forgery token must match too.
 *
 * A request without a live session gets a page, never a redirect to an
 * address it influenced.
 */
final class AuthenticatePortal
{
    public const ATTRIBUTE = 'portal.principal';

    public function __construct(private readonly PortalSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookies->get(PortalCookie::name($request));
        $principal = $this->sessions->authenticate(is_string($cookie) ? $cookie : null);

        if ($principal === null) {
            return response()->view('portal::session-expired', [], 401);
        }

        if (! $request->isMethodSafe() && ! $this->sessions->isValidCsrf($principal, $request->string('_csrf')->toString())) {
            return response()->view('portal::error', [
                'principal' => $principal,
                'csrf' => $this->sessions->csrfToken($principal),
                'title' => 'Formulaire expiré',
                'message' => 'Votre formulaire n\'est plus valide. Retournez en arrière, rechargez la page et recommencez.',
            ], 419);
        }

        $request->attributes->set(self::ATTRIBUTE, $principal);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
