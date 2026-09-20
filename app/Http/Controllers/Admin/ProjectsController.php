<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListOrganizationsCommand;
use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Contracts\OperatorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Projects listing + detail + create. Index accepts `organization_id`
 * filter, detail resolves a single project directly via `GetProject`
 * (review finding, Lot 6 follow-up: this used to be a permanent stub —
 * no port existed to resolve a project without already knowing its
 * organization, so every detail page 404'd).
 */
final class ProjectsController
{
    /**
     * The organization dropdown on the create form fetches one page of
     * `ListOrganizations` and renders every row as an <option> — fine at
     * today's scale, but a page this large stops being a reasonable
     * dropdown well before it stops being a reasonable listing. If
     * production plausibly grows past ~100 organizations, this needs a
     * searchable combobox instead of a fixed-size <select>; out of scope
     * for this pass.
     */
    private const ORGANIZATION_OPTIONS_LIMIT = 100;

    public function __construct(
        private readonly ListProjects $listProjects,
        private readonly GetProject $getProject,
        private readonly CreateProject $createProject,
        private readonly ListOrganizations $listOrganizations,
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

    public function create(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'The projects:write permission is required.');
        }

        $organizations = $this->listOrganizations->list(new ListOrganizationsCommand(
            page: 1,
            perPage: self::ORGANIZATION_OPTIONS_LIMIT,
        ));

        return response()->view('admin.projects.create', [
            'title' => 'New project',
            'organizations' => $organizations->items,
            'selectedOrganizationId' => (string) $request->query('organization_id', ''),
        ]);
    }

    public function store(Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'The projects:write permission is required.');
        }

        /** @var array{name: string, organization_id: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization_id' => ['required', 'uuid'],
        ]);

        try {
            $project = $this->createProject->create(
                new CreateProjectCommand($data['organization_id'], $data['name']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['organization_id' => $e->getMessage()]);
        }

        return redirect()->route('admin.projects.show', ['projectId' => $project->id])
            ->with('status', 'Project created: '.$project->name);
    }
}
