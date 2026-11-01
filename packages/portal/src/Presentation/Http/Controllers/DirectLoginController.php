<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Controllers;

use Core\Portal\Application\Dto\LoginAdmission;
use Core\Portal\Application\UseCase\DirectLoginService;
use Core\Portal\Application\UseCase\PortalSessionService;
use Core\Portal\Presentation\Http\PortalCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ADR-0075 §2 — the public login by code: `GET|POST /portal/{projectId}/login`
 * and `POST .../login/verify`. No session exists yet, so there is no
 * anti-forgery token to check: what protects these doors is the code itself
 * (single use, five attempts) and the budgets.
 *
 * Whatever the phone is, `send()` returns the same page with the same
 * status: the lookup happens after the response has left.
 */
final class DirectLoginController
{
    public function __construct(
        private readonly DirectLoginService $login,
        private readonly PortalSessionService $sessions,
    ) {}

    public function form(string $projectId): Response
    {
        if (! $this->login->isOffered($projectId)) {
            return $this->notFound();
        }

        return $this->page('portal::login', $projectId, [], 200);
    }

    public function send(Request $request, string $projectId): Response
    {
        $phone = $this->field($request, 'phone');
        $ip = $request->ip();

        switch ($this->login->admit($projectId, $phone, $ip)) {
            case LoginAdmission::Disabled:
                return $this->notFound();
            case LoginAdmission::InvalidPhone:
                return $this->page('portal::login', $projectId, ['error' => 'Saisissez un numéro de téléphone valide, avec l\'indicatif du pays (par exemple +237 6XX XX XX XX).', 'phone' => $phone], 422);
            case LoginAdmission::Throttled:
                return $this->tooMany();
            case LoginAdmission::Unavailable:
                return $this->unavailable();
            case LoginAdmission::Accepted:
                break;
        }

        // The lookup and the sending come after the answer: a known phone
        // and a stranger cost the browser exactly the same.
        // Once only: a long-lived application (tests, a worker) keeps its
        // terminating callbacks and would replay this one at every request.
        $login = $this->login;
        $pending = true;
        app()->terminating(static function () use ($login, $projectId, $phone, $ip, &$pending): void {
            if ($pending) {
                $pending = false;
                $login->deliver($projectId, $phone, $ip);
            }
        });

        return $this->page('portal::login-verify', $projectId, ['phone' => $phone], 200);
    }

    public function verify(Request $request, string $projectId): Response|RedirectResponse
    {
        $phone = $this->field($request, 'phone');
        $ip = $request->ip();

        switch ($this->login->admitVerification($projectId, $ip)) {
            case LoginAdmission::Disabled:
                return $this->notFound();
            case LoginAdmission::Throttled:
                return $this->tooMany();
            case LoginAdmission::Unavailable:
                return $this->unavailable();
            default:
                break;
        }

        // The field is not called `code` on purpose: the request log redacts
        // the keys that look like a PIN, and this one is a credential.
        $code = (string) preg_replace('/\s+/', '', $this->field($request, 'login_pin'));
        $exchanged = $this->login->verify($projectId, $phone, $code, $ip);

        if ($exchanged === null) {
            return $this->page('portal::login-verify', $projectId, ['phone' => $phone, 'error' => 'Ce code est incorrect ou n\'est plus valide. Vérifiez-le, ou demandez un nouveau code.'], 422);
        }

        $response = new RedirectResponse($exchanged->landingPath);
        $response->headers->setCookie(PortalCookie::make($request, $exchanged->cookieValue, $exchanged->cookieLifetimeSeconds));

        return $response;
    }

    private function field(Request $request, string $name): string
    {
        return mb_substr($request->string($name)->toString(), 0, 40);
    }

    /**
     * @param view-string $view
     * @param array<string, mixed> $extra
     */
    private function page(string $view, string $projectId, array $extra, int $status): Response
    {
        return response()->view($view, $extra + [
            'projectId' => $projectId,
            'branding' => $this->sessions->brandingOf($projectId),
            'themeProject' => $projectId,
        ], $status);
    }

    private function notFound(): Response
    {
        return response()->view('portal::error', ['title' => 'Introuvable', 'message' => 'Cette page n\'existe pas.'], 404);
    }

    private function tooMany(): Response
    {
        return response()->view('portal::error', ['title' => 'Trop de demandes', 'message' => 'Vous avez fait trop de tentatives. Patientez un peu avant de recommencer.'], 429);
    }

    private function unavailable(): Response
    {
        return response()->view('portal::error', ['title' => 'Service indisponible', 'message' => 'La connexion par code est momentanément indisponible. Réessayez dans quelques minutes.'], 503);
    }
}
