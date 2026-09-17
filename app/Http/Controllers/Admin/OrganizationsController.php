<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\ListProjects;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organizations listing. Filtering by `organization_id` is supported
 * (UUID-shaped). Pagination via page/per_page.
 *
 * Today the listing returns raw `ProjectView` rows for the given org —
 * organizations are surfaced as `ProjectView.organizationId` matches.
 * A future Lot 3 work can add a proper `ListOrganizations` contract; for
 * now this view reads `ListProjects` with an org filter.
 */
final class OrganizationsController
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

        // Build a de-duplicated organization view from projects (one org
        // per group of projects). This is the cheapest "organization list"
        // we can render without a dedicated contract.
        $organizations = [];
        foreach ($page_result->items as $project) {
            $orgId = $project->organizationId;
            if (! isset($organizations[$orgId])) {
                $organizations[$orgId] = [
                    'id' => $orgId,
                    'name' => null,
                ];
            }
        }

        return response()->view('admin.organizations.index', [
            'title' => 'Organizations',
            'organizations' => array_values($organizations),
            'total' => count($organizations),
            'page' => $page,
            'perPage' => $perPage,
            'filters' => ['organization_id' => $orgId],
        ]);
    }
}
