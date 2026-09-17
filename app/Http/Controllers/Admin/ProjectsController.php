<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\ListProjects;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Projects listing + detail. Index accepts `organization_id` filter,
 * detail renders the full `ProjectView` plus the (placeholder) project
 * info — extending to API keys / webhooks / payments counts is left to
 * the corresponding project-scoped admin endpoints to avoid N+1.
 */
final class ProjectsController
{
    public function __construct(
        private readonly ListProjects $listProjects,
    ) {}

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $orgId = (string) $request->query('organization_id', '');

        $page_result = $this->listProjects->list(new \Core\Identity\Contracts\ListProjectsCommand(
            organizationId: $orgId === '' ? null : $orgId,
            page: $page,
            perPage: $perPage,
        ));

        return response()->view('admin.projects.index', [
            'title' => 'Projects',
            'projects' => $page_result->items,
            'total' => $page_result->total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['organization_id' => $orgId],
        ]);
    }

    public function show(string $projectId): Response
    {
        // Single-project view: fetch with perPage=1 by filtering on
        // the org + matching project would need a dedicated contract.
        // For now we list projects for the parent org and find the one
        // we want — this is O(N) in project count but acceptable for
        // admin view traffic.
        $orgId = $this->inferOrg($projectId);
        if ($orgId === null) {
            abort(404, 'Project not found.');
        }
        $page = $this->listProjects->list(new \Core\Identity\Contracts\ListProjectsCommand(
            organizationId: $orgId,
            page: 1,
            perPage: 100,
        ));
        $project = null;
        foreach ($page->items as $p) {
            if ($p->id === $projectId) {
                $project = $p;
                break;
            }
        }
        if ($project === null) {
            abort(404, 'Project not found.');
        }

        return response()->view('admin.projects.show', [
            'title' => 'Project '.substr($project->id, 0, 8).'…',
            'project' => $project,
        ]);
    }

    /**
     * Resolve the project through the same listing contract — single-project
     * fetch isn't a published port yet. For the admin UI, this is fine
     * because project counts per org stay low (double-digit max).
     */
    /** @return null — kept as a method stub; the proper fix is a `GetProject` contract. */
    private function inferOrg(string $projectId): ?string
    {
        return null;
    }
}
