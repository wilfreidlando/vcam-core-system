<?php

declare(strict_types=1);

namespace Core\Support\Http\Middleware;

use Closure;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0081 S-14 (audit de sécurité 2026-10-29, écart #13) — l'application
 * borne elle-même ce qu'elle accepte, sans compter sur le seul `client_max_body_size`
 * du serveur web : un corps de plus de 1 Mio est refusé en `413`, et un corps
 * JSON imbriqué sur plus de 20 niveaux en `400`, tous deux en
 * `application/problem+json`, avant que le moindre contrôleur ou validateur
 * ne le décode.
 *
 * Posé en tête du groupe `api` (alias `body.limit`). L'en-tête `Content-Length`
 * est lu d'abord : un corps annoncé trop gros est refusé sans être lu.
 */
final readonly class LimitRequestBody
{
    public const MAX_BYTES = 1_048_576;

    public const MAX_JSON_DEPTH = 20;

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if (is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        $content = $request->getContent();

        if (strlen($content) > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        if ($content !== '' && $request->isJson() && self::exceedsDepth($content)) {
            return ProblemDetails::make(
                'json-too-deep',
                'Request body too deeply nested',
                400,
                'The JSON body is nested on more than '.self::MAX_JSON_DEPTH.' levels.',
            );
        }

        return $next($request);
    }

    private function tooLarge(): Response
    {
        return ProblemDetails::make(
            'payload-too-large',
            'Request body too large',
            413,
            'The request body exceeds '.self::MAX_BYTES.' bytes.',
        );
    }

    private static function exceedsDepth(string $json): bool
    {
        json_decode($json, true, self::MAX_JSON_DEPTH + 1);

        return json_last_error() === JSON_ERROR_DEPTH;
    }
}
