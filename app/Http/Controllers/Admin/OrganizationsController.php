<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListOrganizationsCommand;
use Core\Identity\Contracts\OperatorContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organizations listing, backed by `ListOrganizations` (review finding,
 * Lot 6 follow-up: this used to fake an organization list by
 * de-duplicating a single page of `ListProjects` results, which
 * under-counted and mis-paginated).
 */
final class OrganizationsController
{
    public function __construct(
        private readonly ListOrganizations $listOrganizations,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:read')) {
            abort(403, 'The projects:read permission is required.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $pageResult = $this->listOrganizations->list(new ListOrganizationsCommand(
            page: $page,
            perPage: $perPage,
        ));

        return response()->view('admin.organizations.index', [
            'title' => 'Organizations',
            'organizations' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }
}
