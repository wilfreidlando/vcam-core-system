<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Middleware;

use Closure;
use Core\Identity\Application\UseCase\ResolveProjectFromBearerToken;
use Core\Identity\Contracts\ProjectContext;
use Core\Identity\Domain\Exception\InvalidApiKey;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * The machine-identity authentication path (spec technique §5.1, Plan 2):
 * every capability route runs through this before it runs its own logic.
 *
 * Resolves the caller's `ProjectContext` and binds it into the container so
 * downstream code — a controller in this module or, once other modules exist,
 * their controllers too — can simply type-hint `ProjectContext` rather than
 * re-parsing the Authorization header.
 */
final class ResolveProjectMiddleware
{
    public function __construct(
        private readonly ResolveProjectFromBearerToken $resolveProject,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return ProblemDetails::unauthenticated('A Bearer API key is required.');
        }

        try {
            $context = ($this->resolveProject)($token);
        } catch (InvalidApiKey) {
            return ProblemDetails::unauthenticated('The provided API key is invalid or has been revoked.');
        } catch (ProjectNotActive) {
            return ProblemDetails::forbidden('This project is not active.');
        }

        app()->instance(ProjectContext::class, $context);

        // ADR-0059: every log line of this request (and of the jobs it
        // causes — Context travels into queued jobs) says which Project
        // and environment it belongs to.
        Context::add('project_id', $context->projectId);
        Context::add('environment', $context->environment->value);
        Context::add('api_key_prefix', $context->apiKeyPrefix);

        return $next($request);
    }
}
