<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Middleware;

use Closure;
use Core\Identity\Application\UseCase\ResolveProjectFromBearerToken;
use Core\Identity\Contracts\ProjectContext;
use Core\Identity\Domain\Exception\ApiKeyOriginNotAllowed;
use Core\Identity\Domain\Exception\InvalidApiKey;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
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
            // `ip()` honours the trusted-proxy configuration only (ADR-0082 §4).
            $context = ($this->resolveProject)($token, $request->ip());
        } catch (ApiKeyOriginNotAllowed $e) {
            // S-20: a refused origin is worth a line an alert can count.
            Log::warning('API key used from a network address outside its allowed blocks.', [
                'api_key_id' => $e->apiKeyId,
                'api_key_prefix' => $e->apiKeyPrefix,
                'project_id' => $e->projectId,
                'client_ip' => $e->clientIp,
            ]);

            return ProblemDetails::make('key-origin-not-allowed', 'Key origin not allowed', 403, 'This API key may not be used from this network address.');
        } catch (InvalidApiKey) {
            return ProblemDetails::unauthenticated('The provided API key is invalid or has been revoked.');
        } catch (ProjectNotActive $e) {
            // ADR-0069 §3 — a suspended project says so plainly (its
            // administrator is the one who can lift it), an archived one
            // says it is over.
            $type = str_contains($e->getMessage(), 'archived') ? 'project-archived' : 'project-suspended';

            return ProblemDetails::make(
                $type,
                $type === 'project-archived' ? 'Project archived' : 'Project suspended',
                403,
                $type === 'project-archived'
                    ? 'This project is archived and no longer accepts requests.'
                    : 'This project is suspended and no longer accepts requests.',
            );
        }

        app()->instance(ProjectContext::class, $context);

        // ADR-0059: every log line of this request (and of the jobs it
        // causes — Context travels into queued jobs) says which Project
        // it belongs to.
        Context::add('project_id', $context->projectId);
        Context::add('api_key_prefix', $context->apiKeyPrefix);
        Context::add('api_key_id', $context->apiKeyId);

        return $next($request);
    }
}
