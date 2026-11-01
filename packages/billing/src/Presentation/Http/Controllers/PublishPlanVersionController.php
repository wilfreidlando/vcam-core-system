<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/plans/{code}/versions/{version}/publish` — ADR-0071 §2.
 *
 * The version becomes the one new subscriptions take; the ones already
 * signed keep theirs.
 */
final class PublishPlanVersionController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly PublishPlanVersion $publishPlanVersion,
    ) {}

    /**
     * Publish a version of a plan of the calling Project.
     *
     * Publishing freezes the terms: the only transition a published
     * version may undergo afterwards is `published → retired`. The
     * contracts already signed keep the version they were signed on
     * (ADR-0071 §2).
     *
     * Requires the `plans:write` scope.
     *
     * @throws PlanNotFound the plan or the version doesn't exist, or belongs to another project (404)
     * @throws PlanVersionNotFound the plan has no such version (404)
     * @throws PlanVersionNotEditable the version is already published or retired (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::PLAN_VERSION, description: 'The published version: it is frozen from now on.')]
    public function __invoke(string $code, string $version, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('plans:write')) {
            return ProblemDetails::forbidden('The plans:write scope is required.');
        }

        // The version is part of the identity of the resource: a version
        // that is not a number is a version that does not exist.
        if (! ctype_digit($version)) {
            return ProblemDetails::make(
                'plan-not-found',
                'Plan not found',
                404,
                sprintf('No version "%s" of the plan "%s" exists in this project.', $version, $code),
            );
        }

        $view = $this->publishPlanVersion->publish(new PublishPlanVersionCommand(
            projectId: $project->projectId,
            planCode: $code,
            version: (int) $version,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->planVersionView($view));
    }
}
