<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\GetOrganization;
use Core\Identity\Contracts\GetOrganizationCommand;
use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListOrganizationsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\UpdateOrganization;
use Core\Identity\Contracts\UpdateOrganizationCommand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organizations listing + create + edit, backed by `ListOrganizations`/
 * `CreateOrganization`/`GetOrganization`/`UpdateOrganization` (review
 * finding, Lot 6 follow-up: this used to fake an organization list by
 * de-duplicating a single page of `ListProjects` results, which
 * under-counted and mis-paginated; edit — name + status — was entirely
 * absent, unlike operators, which review finding: "je ne vois pas les
 * actions sur projet ni organisation pour modifier, changer le statut").
 */
final class OrganizationsController
{
    /**
     * The only two statuses this console offers — the Domain model itself
     * never constrained the column beyond `isActive()`'s `=== 'active'`
     * check, so this list is the HTTP-boundary's own choice, not a Domain
     * rule; growing it later (e.g. a "pending" onboarding state) means
     * adding a value here, nothing structural.
     */
    private const STATUSES = ['active', 'suspended'];

    public function __construct(
        private readonly ListOrganizations $listOrganizations,
        private readonly CreateOrganization $createOrganization,
        private readonly GetOrganization $getOrganization,
        private readonly UpdateOrganization $updateOrganization,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:read')) {
            abort(403, 'La permission projects:read est requise.');
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
            'title' => 'Organisations',
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
            abort(403, 'La permission projects:write est requise.');
        }

        return response()->view('admin.organizations.create', [
            'title' => 'Nouvelle organisation',
        ]);
    }

    public function store(Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'La permission projects:write est requise.');
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
            ->with('status', 'Organisation créée : '.$data['name']);
    }

    public function edit(string $organizationId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'La permission projects:write est requise.');
        }

        Validator::make(['organization_id' => $organizationId], ['organization_id' => 'uuid'])->validate();

        try {
            $organization = $this->getOrganization->get(new GetOrganizationCommand($organizationId));
        } catch (RuntimeException) {
            abort(404, 'Organisation introuvable.');
        }

        return response()->view('admin.organizations.edit', [
            'title' => "Modifier l'organisation",
            'organization' => $organization,
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(string $organizationId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('projects:write')) {
            abort(403, 'La permission projects:write est requise.');
        }

        Validator::make(['organization_id' => $organizationId], ['organization_id' => 'uuid'])->validate();

        /** @var array{name: string, status: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
        ]);

        try {
            $this->updateOrganization->update(
                new UpdateOrganizationCommand($organizationId, $data['name'], $data['status']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('admin.organizations.index')
            ->with('status', 'Organisation mise à jour : '.$data['name']);
    }
}
