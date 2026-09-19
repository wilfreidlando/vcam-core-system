<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\OperatorContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Projects listing + detail. Index accepts `organization_id` filter,
 * detail resolves a single project directly via `GetProject` (review
 * finding, Lot 6 follow-up: this used to be a permanent stub — no port
 * existed to resolve a project without already knowing its
 * organization, so every detail page 404'd).
 */
final class ProjectsController
{
    public function __construct(
        private readonly ListProjects $listProjects,
        private readonly GetProject $getProject,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:read')) {
            abort(403, 'The projects:read permission is required.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $orgId = (string) $request->query('organization_id', '');

        if ($orgId !== '') {
            Validator::make(['organization_id' => $orgId], ['organization_id' => 'uuid'])->validate();
        }

        $pageResult = $this->listProjects->list(new ListProjectsCommand(
            organizationId: $orgId === '' ? null : $orgId,
            page: $page,
            perPage: $perPage,
        ));

        return response()->view('admin.projects.index', [
            'title' => 'Projects',
            'projects' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['organization_id' => $orgId],
        ]);
    }

    public function show(string $projectId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:read')) {
            abort(403, 'The projects:read permission is required.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Project not found.');
        }

        return response()->view('admin.projects.show', [
            'title' => 'Project '.substr($project->id, 0, 8).'…',
            'project' => $project,
        ]);
    }
}
