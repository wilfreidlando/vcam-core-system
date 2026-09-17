<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ListProjectsController
{
    public function __construct(
        private readonly ListProjects $listProjects,
    ) {}

    /**
     * List projects.
     *
     * Optionally scoped to an `organization_id`. Every other project-scoped
     * admin listing (payments, wallets, notifications) starts from a
     * `project_id` found here. Requires `projects:read`.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('projects:read')) {
            return ProblemDetails::forbidden('The projects:read permission is required.');
        }

        $organizationIdRaw = $request->query('organization_id');
        $organizationId = is_string($organizationIdRaw) && $organizationIdRaw !== '' ? $organizationIdRaw : null;

        if ($organizationId !== null) {
            // A malformed (non-UUID) organization_id would otherwise
            // reach a `WHERE organization_id = ?` against a Postgres
            // `uuid` column and crash with an uncaught QueryException —
            // a clean 422 via Laravel's existing ValidationException
            // mapping instead (review finding, Lot 6 follow-up).
            Validator::make(['organization_id' => $organizationId], ['organization_id' => 'uuid'])->validate();
        }

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $result = $this->listProjects->list(new ListProjectsCommand($organizationId, $page, $perPage));

        return new JsonResponse([
            'data' => array_map(static fn ($project): array => [
                'id' => $project->id,
                'organization_id' => $project->organizationId,
                'name' => $project->name,
                'status' => $project->status,
            ], $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
