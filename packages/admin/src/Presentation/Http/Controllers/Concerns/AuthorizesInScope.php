<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers\Concerns;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use RuntimeException;

/**
 * ADR-0069 §2 / S-04 — the JSON plane's two authorization questions, the
 * same as the web console's (`App\Http\Controllers\Admin\Concerns\ResolvesScopedProject`):
 *
 * 1. `permittedScope()` — the account's scope narrowed to the permission,
 *    `null` when it holds it nowhere (the caller answers 403: that reveals
 *    nothing about any resource);
 * 2. `projectRefusal()` — for the project named by the request: 404 when
 *    the account may not see it (never confirm a foreign resource exists),
 *    403 when it sees it but its role in the project's organization lacks
 *    the permission.
 */
trait AuthorizesInScope
{
    private function permittedScope(OperatorContext $operator, string $permission): ?AccessScope
    {
        $scope = $operator->scopeFor($permission);

        return $scope->isEmpty() ? null : $scope;
    }

    /**
     * Why the account may not act on the project named by the request —
     * `'not-found'` (404: it may not see it, never confirm a foreign
     * resource exists), `'forbidden'` (403: it sees it, its role there lacks
     * the permission) — or `null` when it may.
     *
     * A reason, not a response: the controller writes the problem response
     * itself, as a direct `ProblemDetails` return, so the API documentation
     * keeps inferring every response of the route.
     */
    private function projectRefusal(GetProject $getProject, OperatorContext $operator, string $projectId, string $permission): ?string
    {
        try {
            $project = $getProject->get(new GetProjectCommand($projectId), $operator->scope);
        } catch (RuntimeException) {
            return 'not-found';
        }

        return $operator->scopeFor($permission)->allowsProject($project->id) ? null : 'forbidden';
    }
}
