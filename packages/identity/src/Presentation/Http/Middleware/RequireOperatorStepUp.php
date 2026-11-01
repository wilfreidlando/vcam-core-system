<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Middleware;

use Closure;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `operator.step_up` — écart #5 (annexe sécurité 2026-10-29, G-4).
 *
 * À placer APRÈS `operator.session` sur les routes **mutantes** sensibles de la
 * console (approuver une exception d'argent ou une correction du grand livre,
 * émettre/faire tourner/révoquer une clé d'API, webhooks, effacement d'un client,
 * rôle ou invitation d'un opérateur). Une requête sûre (GET/HEAD/OPTIONS) passe :
 * l'élévation protège l'action, pas la lecture.
 *
 * Sans preuve TOTP de moins de cinq minutes en session :
 *  - navigation : redirection vers `/admin/step-up`, la page d'où l'on vient est
 *    retenue pour y revenir ;
 *  - appel JSON : `403 problem+json` (`step-up-required`).
 * L'action ne s'exécute jamais, et rien ne plante.
 */
final class RequireOperatorStepUp
{
    public function __construct(
        private readonly StepUpGate $gate,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        // Pas d'opérateur résolu : ce middleware est mal placé. On échoue fermé.
        if (! app()->bound(OperatorContext::class)) {
            return $this->toLogin($request);
        }

        $context = app(OperatorContext::class);

        if (! $this->gate->isRequiredFor($context->operatorId) || $this->gate->isElevated()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return ProblemDetails::make(
                'step-up-required',
                'Step-up required',
                403,
                'Cette action sensible exige un code TOTP saisi dans les 5 dernières minutes.',
                ['step_up_url' => route('admin.step-up.show')],
            );
        }

        $this->gate->rememberDestination($this->destination($request));

        return redirect()->route('admin.step-up.show');
    }

    /**
     * La page où revenir : celle d'où vient la requête (le formulaire), pas la
     * cible d'un POST. Toujours un chemin local de la console — jamais une URL
     * étrangère (pas de redirection ouverte).
     */
    private function destination(Request $request): string
    {
        $previous = url()->previous();
        $host = parse_url($previous, PHP_URL_HOST);
        $path = parse_url($previous, PHP_URL_PATH);
        $query = parse_url($previous, PHP_URL_QUERY);

        if (
            $host === $request->getHost()
            && is_string($path)
            && str_starts_with($path, '/admin')
            && ! str_starts_with($path, '/admin/step-up')
        ) {
            return $path.(is_string($query) && $query !== '' ? '?'.$query : '');
        }

        return route('admin.dashboard', [], false);
    }

    private function toLogin(Request $request): Response
    {
        if ($request->expectsJson()) {
            return ProblemDetails::unauthenticated('Une session opérateur est requise.');
        }

        return redirect()->route('admin.login.show');
    }
}
