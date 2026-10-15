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
use Core\Identity\Contracts\UpdateProject;
use Core\Identity\Contracts\UpdateProjectCommand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Projects listing + detail + create + edit. Index accepts
 * `organization_id` filter, detail resolves a single project directly via
 * `GetProject` (review finding, Lot 6 follow-up: this used to be a
 * permanent stub — no port existed to resolve a project without already
 * knowing its organization, so every detail page 404'd). Edit — name +
 * status — mirrors the same review finding organizations got: "je ne vois
 * pas les actions sur projet ni organisation pour modifier, changer le
 * statut".
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

    /** Same HTTP-boundary choice as `OrganizationsController::STATUSES`. */
    private const STATUSES = ['active', 'suspended'];

    public function __construct(
        private readonly ListProjects $listProjects,
        private readonly GetProject $getProject,
        private readonly CreateProject $createProject,
        private readonly ListOrganizations $listOrganizations,
        private readonly UpdateProject $updateProject,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:read')) {
            abort(403, 'La permission projects:read est requise.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $orgId = (string) $request->query('organization_id', '');
        $searchRaw = (string) $request->query('search', '');

        if ($orgId !== '') {
            Validator::make(['organization_id' => $orgId], ['organization_id' => 'uuid'])->validate();
        }

        $pageResult = $this->listProjects->list(new ListProjectsCommand(
            organizationId: $orgId === '' ? null : $orgId,
            page: $page,
            perPage: $perPage,
            search: $searchRaw === '' ? null : $searchRaw,
        ));

        // Same organization fetch the create-form dropdown already does
        // (capped at ORGANIZATION_OPTIONS_LIMIT) — reused here to resolve
        // each row's organization NAME instead of a raw truncated UUID
        // (review finding: "tu mets les IDs sur les listings au lieu des
        // noms"). No `GetOrganization` single-lookup port exists, only
        // `ListOrganizations`, so this fetches the whole (small, capped)
        // set once rather than one call per row.
        $organizationNames = [];
        foreach ($this->listOrganizations->list(new ListOrganizationsCommand(page: 1, perPage: self::ORGANIZATION_OPTIONS_LIMIT))->items as $organization) {
            $organizationNames[$organization->id] = $organization->name;
        }

        return response()->view('admin.projects.index', [
            'title' => 'Projets',
            'projects' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['organization_id' => $orgId],
            'search' => $searchRaw,
            'organizationNames' => $organizationNames,
        ]);
    }

    public function show(string $projectId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:read')) {
            abort(403, 'La permission projects:read est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        return response()->view('admin.projects.show', [
            'title' => 'Projet '.substr($project->id, 0, 8).'…',
            'project' => $project,
            'createdApiKey' => session('admin.projects.created_api_key'),
        ]);
    }

    public function create(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'La permission projects:write est requise.');
        }

        $organizations = $this->listOrganizations->list(new ListOrganizationsCommand(
            page: 1,
            perPage: self::ORGANIZATION_OPTIONS_LIMIT,
        ));

        return response()->view('admin.projects.create', [
            'title' => 'Nouveau projet',
            'organizations' => $organizations->items,
            'selectedOrganizationId' => (string) $request->query('organization_id', ''),
        ]);
    }

    public function store(Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'La permission projects:write est requise.');
        }

        /** @var array{name: string, organization_id: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization_id' => ['required', 'uuid'],
        ]);

        try {
            $provisioned = $this->createProject->create(
                new CreateProjectCommand($data['organization_id'], $data['name']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['organization_id' => $e->getMessage()]);
        }

        // One-shot display on the project page — same pattern as a
        // standalone key/webhook-secret issuance, never retrievable again.
        // @phpstan-ignore-next-line — session()->flash() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->flash('admin.projects.created_api_key', $provisioned->apiKey->plaintextToken);

        return redirect()->route('admin.projects.show', ['projectId' => $provisioned->project->id])
            ->with('status', 'Projet créé : '.$provisioned->project->name);
    }

    public function edit(string $projectId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'La permission projects:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        return response()->view('admin.projects.edit', [
            'title' => 'Modifier le projet',
            'project' => $project,
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'La permission projects:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        /** @var array{name: string, status: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
        ]);

        try {
            $this->updateProject->update(
                new UpdateProjectCommand($projectId, $data['name'], $data['status']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('admin.projects.show', ['projectId' => $projectId])
            ->with('status', 'Projet mis à jour : '.$data['name']);
    }
}
