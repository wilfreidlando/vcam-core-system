<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ProposeTenantSchema;
use Core\Billing\Contracts\ProposeTenantSchemaCommand;
use Core\Billing\Domain\Exception\InvalidTenantSchema;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\ProposeTenantSchemaRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `PUT /v1/projects/{projectId}/tenant-schema` — ADR-0122 §2.
 *
 * The client application **proposes** the form of its tenant: the key it
 * designates, the pattern its references must respect, and the attribute
 * keys it expects the Core to collect.
 *
 * The proposal is not the form in force: it waits for the operator to read
 * it and accept it (ADR-0122 §2). The gesture writes no customer, no
 * subscription and no invoice — it describes a form (ADR-0122 §8).
 */
final class ProposeTenantSchemaController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly ProposeTenantSchema $proposeTenantSchema,
    ) {}

    /**
     * Propose the tenant form of the calling Project.
     *
     * Answers `201` with the proposed declaration: it waits for the
     * operator to read it — only an accepted declaration is ever rendered
     * (ADR-0122 §2).
     *
     * Requires the `tenant_schema:write` scope. That scope describes a
     * **form**, not a write on the commerce: the template of the «
     * Application cliente » carries it, and still carries no write scope
     * on the customers, the subscriptions or the invoices (ADR-0122 §8).
     *
     * @throws InvalidTenantSchema the declared form is refused (422)
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::TENANT_SCHEMA, description: 'The proposed declaration, waiting for the operator.')]
    public function __invoke(string $projectId, ProposeTenantSchemaRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('tenant_schema:write')) {
            return ProblemDetails::forbidden('The tenant_schema:write scope is required.');
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

        /** @var array{unique_key: string, unique_key_pattern: string, fields: list<array{key: string, label: string, type: string, required: bool, max_length: int|null, source: string}>} $data */
        $data = $request->validated();

        try {
            $view = $this->proposeTenantSchema->propose(new ProposeTenantSchemaCommand(
                projectId: $project->projectId,
                uniqueKey: $data['unique_key'],
                uniqueKeyPattern: $data['unique_key_pattern'],
                fields: $data['fields'],
                actor: 'project:'.$project->projectId,
                correlationId: TraceContext::correlationId(),
            ));
        } catch (InvalidTenantSchema $exception) {
            return ProblemDetails::make(
                'invalid-tenant-schema',
                'The tenant schema is invalid',
                422,
                $exception->getMessage(),
            );
        }

        return new JsonResponse($this->tenantSchemaView($view), 201);
    }
}
