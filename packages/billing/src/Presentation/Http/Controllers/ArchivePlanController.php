<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ArchivePlan;
use Core\Billing\Contracts\ArchivePlanCommand;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/plans/{code}/archive` — ADR-0071 §5.
 *
 * The plan takes no new subscription; the running ones continue to
 * their end.
 */
final class ArchivePlanController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly ArchivePlan $archivePlan,
    ) {}

    /**
     * Archive a plan of the calling Project.
     *
     * An archived plan takes no new subscription; the contracts already
     * signed on it continue to their end (ADR-0071 §5).
     *
     * Requires the `plans:write` scope.
     *
     * @throws PlanNotFound the plan doesn't exist, or belongs to another project (404)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::PLAN, description: 'The archived plan: it takes no new contract.')]
    public function __invoke(string $code, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('plans:write')) {
            return ProblemDetails::forbidden('The plans:write scope is required.');
        }

        $view = $this->archivePlan->archive(new ArchivePlanCommand(
            projectId: $project->projectId,
            planCode: $code,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->planView($view));
    }
}
