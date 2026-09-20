<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class CreateProjectController
{
    public function __construct(
        private readonly CreateProject $createProject,
    ) {}

    /**
     * Create a project under an organization.
     *
     * Requires `projects:write` (covered by Platform Admin's `*`
     * wildcard). Audits internally — `identity` has `audit` module access.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('projects:write')) {
            return ProblemDetails::forbidden('The projects:write permission is required.');
        }

        /** @var array{organization_id: string, name: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $created = $this->createProject->create(
                new CreateProjectCommand($data['organization_id'], $data['name']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            // The concrete exception (OrganizationNotFound) lives in
            // identity's Domain layer; admin must not reference it (G3c).
            return ProblemDetails::make('organization-not-found', 'Organization not found', 422, $e->getMessage());
        }

        return new JsonResponse([
            'id' => $created->id,
            'organization_id' => $created->organizationId,
            'name' => $created->name,
            'status' => $created->status,
        ], 201);
    }
}
