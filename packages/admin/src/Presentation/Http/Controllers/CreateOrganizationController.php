<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Http\Controllers;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CreateOrganizationController
{
    public function __construct(
        private readonly CreateOrganization $createOrganization,
    ) {}

    /**
     * Create an organization.
     *
     * Requires `projects:write` (covered by Platform Admin's `*`
     * wildcard). Audits internally — `identity` has `audit` module access.
     */
    public function __invoke(Request $request, OperatorContext $operator): JsonResponse|Response
    {
        if (! $operator->hasPermission('projects:write')) {
            return ProblemDetails::forbidden('The projects:write permission is required.');
        }

        /** @var array{name: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $created = $this->createOrganization->create(
            new CreateOrganizationCommand($data['name']),
            actor: 'operator:'.$operator->operatorId,
            correlationId: null,
        );

        return new JsonResponse([
            'id' => $created->id,
            'name' => $created->name,
            'status' => $created->status,
        ], 201);
    }
}
