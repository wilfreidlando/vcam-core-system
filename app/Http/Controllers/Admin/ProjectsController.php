<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
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
    use ResolvesScopedProject;

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

    /**
     * Every status the model accepts (ADR-0069 §3). `archived` is here
     * but not in `STATUSES`: archiving is **not** a free choice — it is
     * reserved to the owner of the organization and to the platform
     * administrator (`projects:archive`), so a request that carries it
     * from anyone else is answered 403, not silently downgraded.
     */
    private const ALL_STATUSES = ['active', 'suspended', 'archived'];

    public function __construct(
        private readonly ListProjects $listProjects,
        private readonly GetProject $getProject,
        private readonly CreateProject $createProject,
        private readonly ListOrganizations $listOrganizations,
        private readonly UpdateProject $updateProject,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        $scope = $this->permittedScope($operator, 'projects:read');

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
        ), $scope);

        // Same organization fetch the create-form dropdown already does
        // (capped at ORGANIZATION_OPTIONS_LIMIT) — reused here to resolve
        // each row's organization NAME instead of a raw truncated UUID
        // (review finding: "tu mets les IDs sur les listings au lieu des
        // noms"). No `GetOrganization` single-lookup port exists, only
        // `ListOrganizations`, so this fetches the whole (small, capped)
        // set once rather than one call per row.
        $organizationNames = [];
        foreach ($this->listOrganizations->list(new ListOrganizationsCommand(page: 1, perPage: self::ORGANIZATION_OPTIONS_LIMIT), $scope)->items as $organization) {
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
        $scope = $this->permittedScope($operator, 'projects:read');

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId), $scope);
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
        // ADR-0069 §2 — only the organizations where the account may
        // create a project are offered.
        $scope = $this->permittedScope($operator, 'projects:write');

        $organizations = $this->listOrganizations->list(new ListOrganizationsCommand(
            page: 1,
            perPage: self::ORGANIZATION_OPTIONS_LIMIT,
        ), $scope);

        return response()->view('admin.projects.create', [
            'title' => 'Nouveau projet',
            'organizations' => $organizations->items,
            'selectedOrganizationId' => (string) $request->query('organization_id', ''),
        ]);
    }

    public function store(Request $request, OperatorContext $operator): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'projects:write');

        /** @var array{name: string, organization_id: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization_id' => ['required', 'uuid'],
        ]);

        // ADR-0069 §2 — the organization comes from the form: it must be one
        // the account sees (404 otherwise — never confirm a foreign one
        // exists), and one where its role may create a project (403).
        if (! $operator->scope->allowsOrganization($data['organization_id'])) {
            abort(404, 'Organisation introuvable.');
        }
        if (! $scope->allowsOrganization($data['organization_id'])) {
            abort(403, 'La permission projects:write est requise dans cette organisation.');
        }

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
        $this->permittedScope($operator, 'projects:write');
        $project = $this->scopedProject($this->getProject, $operator, $projectId, 'projects:write');

        return response()->view('admin.projects.edit', [
            'title' => 'Modifier le projet',
            'project' => $project,
            'statuses' => $this->statusesFor($operator, $projectId),
        ]);
    }

    public function update(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        $this->permittedScope($operator, 'projects:write');

        // ADR-0069 §2 — the project must be in the account's scope before
        // it is written to (404), and the account's role in its
        // organization must carry the permission (403).
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'projects:write');

        /** @var array{name: string, status: string, reason?: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:'.implode(',', self::ALL_STATUSES)],
            // ADR-0069 §3 — a status change is audited with its reason.
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // ADR-0069 §1 — archiving is final and reserved to the owner of the
        // organization (`org_owner`, through its wildcard) or the platform
        // administrator: an `org_admin` manages projects, it does not close
        // them for good.
        if ($data['status'] === 'archived' && ! $operator->scopeFor('projects:archive')->allowsProject($projectId)) {
            abort(403, 'Seul le propriétaire de l\'organisation peut archiver un projet.');
        }

        try {
            $this->updateProject->update(
                new UpdateProjectCommand($projectId, $data['name'], $data['status'], reason: $data['reason'] ?? null),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('admin.projects.show', ['projectId' => $projectId])
            ->with('status', 'Projet mis à jour : '.$data['name']);
    }

    /**
     * The statuses this account may set on this project: the two
     * reversible ones, plus `archived` when the account holds
     * `projects:archive` on it — ADR-0069 §3 reserves the archive to the
     * owner of the organization (`org_owner`, through its wildcard) and to
     * the platform administrator.
     *
     * @return list<string>
     */
    private function statusesFor(OperatorContext $operator, string $projectId): array
    {
        if ($operator->scopeFor('projects:archive')->allowsProject($projectId)) {
            return [...self::STATUSES, 'archived'];
        }

        return self::STATUSES;
    }
}
