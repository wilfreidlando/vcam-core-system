<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\GetTenantSchema;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/projects/{projectId}/tenant-schema` — ADR-0130 §4.
 *
 * L'application relit **la forme en vigueur** de son locataire : les
 * clés que le Core collecte, les types, les obligations, le motif que
 * doit respecter la référence.
 *
 * ADR-0122 §2 — la forme en vigueur est celle que l'opérateur a
 * **acceptée**. La proposition en attente est rendue à côté : elle
 * attend sa lecture, et l'application a le droit de savoir qu'elle
 * existe — jamais de la traiter comme la forme en vigueur.
 */
final class GetTenantSchemaController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly GetTenantSchema $tenantSchemas,
    ) {}

    /**
     * Read the tenant form of the calling Project.
     *
     * Answers `200` with the accepted declaration (`null` when the
     * project has none yet) and the pending proposal (`null` when there
     * is none).
     *
     * Requires the `tenant_schema:read` scope — the same family as
     * `tenant_schema:write`, and just as harmless: a form, never a
     * value (ADR-0122 §8).
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::TENANT_SCHEMA_STATE, description: 'The declaration in force, and the one waiting for the operator.')]
    public function __invoke(string $projectId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('tenant_schema:read')) {
            return ProblemDetails::forbidden('The tenant_schema:read scope is required.');
        }

        // The declaration is the one of the calling project: the path
        // never names another one (ADR-0069 §2).
        if ($projectId !== $project->projectId) {
            return ProblemDetails::make(
                'project-not-found',
                'Project not found',
                404,
                'No project carries this identifier for this caller.',
            );
        }

        // ADR-0069 §2 — the public API plane's scope is the calling
        // project itself: the declaration never crosses the projects.
        $scope = AccessScope::forProject($project->projectId);

        $accepted = $this->tenantSchemas->accepted($projectId, $scope);
        $pending = $this->tenantSchemas->pending($projectId, $scope);

        return new JsonResponse([
            // The keys are the states of a declaration, not a provider's
            // vocabulary — the same word the Core already uses for the
            // canonical status of a form (see `TenantSchemaStatus`).
            // @phpstan-ignore core.providerStatusLeak
            'accepted' => $accepted === null ? null : $this->tenantSchemaView($accepted),
            'pending' => $pending === null ? null : $this->tenantSchemaView($pending),
        ]);
    }
}
