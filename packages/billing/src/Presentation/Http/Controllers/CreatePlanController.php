<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Domain\Exception\InvalidPlan;
use Core\Billing\Domain\Exception\PlanCodeAlreadyTaken;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\CreatePlanRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/plans` — ADR-0071 §5.
 *
 * The plan is created `draft`: it enters the catalogue only once a
 * version is published (ADR-0071 §2).
 */
final class CreatePlanController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly CreatePlan $createPlan,
    ) {}

    /**
     * Create a plan in the calling Project's catalogue.
     *
     * The plan is created `draft` and carries no terms yet: it enters
     * the catalogue when its first version is published (ADR-0071 §2).
     * The `code` is the project's own stable identifier — it never
     * changes and no other plan of the project may carry it.
     *
     * Requires the `plans:write` scope.
     *
     * @throws PlanCodeAlreadyTaken another plan of this project already carries this code (409)
     * @throws InvalidPlan the code or the visibility is not one the Core accepts (422)
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::PLAN, description: 'The draft plan, with no terms yet.')]
    public function __invoke(CreatePlanRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('plans:write')) {
            return ProblemDetails::forbidden('The plans:write scope is required.');
        }

        /** @var array{code: string, name: string, description?: string|null, visibility?: string|null, sort_order?: int|null} $data */
        $data = $request->validated();

        $view = $this->createPlan->create(new CreatePlanCommand(
            projectId: $project->projectId,
            code: $data['code'],
            name: $data['name'],
            description: $data['description'] ?? null,
            visibility: $data['visibility'] ?? 'public',
            sortOrder: $data['sort_order'] ?? 0,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->planView($view), 201);
    }
}
