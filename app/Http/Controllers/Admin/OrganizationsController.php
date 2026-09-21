<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListOrganizationsCommand;
use Core\Identity\Contracts\OperatorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organizations listing + create, backed by `ListOrganizations`/
 * `CreateOrganization` (review finding, Lot 6 follow-up: this used to
 * fake an organization list by de-duplicating a single page of
 * `ListProjects` results, which under-counted and mis-paginated).
 */
final class OrganizationsController
{
    public function __construct(
        private readonly ListOrganizations $listOrganizations,
        private readonly CreateOrganization $createOrganization,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:read')) {
            abort(403, 'The projects:read permission is required.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $searchRaw = (string) $request->query('search', '');
        $search = $searchRaw === '' ? null : $searchRaw;

        $pageResult = $this->listOrganizations->list(new ListOrganizationsCommand(
            page: $page,
            perPage: $perPage,
            search: $search,
        ));

        return response()->view('admin.organizations.index', [
            'title' => 'Organizations',
            'organizations' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'search' => $searchRaw,
        ]);
    }

    public function create(OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'The projects:write permission is required.');
        }

        return response()->view('admin.organizations.create', [
            'title' => 'New organization',
        ]);
    }

    public function store(Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'The projects:write permission is required.');
        }

        /** @var array{name: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->createOrganization->create(
            new CreateOrganizationCommand($data['name']),
            actor: 'operator:'.$operator->operatorId,
            correlationId: null,
        );

        return redirect()->route('admin.organizations.index')
            ->with('status', 'Organization created: '.$data['name']);
    }
}
