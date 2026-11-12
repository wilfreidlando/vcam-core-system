<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\UpdatePlanVersion;
use Core\Billing\Contracts\UpdatePlanVersionCommand;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionConflict;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\UpdatePlanVersionRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `PUT /v1/plans/{code}/versions/{version}` — ADR-0071 §2.
 *
 * The terms of a **draft**, corrected. The catalogue holds one draft per
 * offer: a draft written wrong is corrected, never abandoned.
 *
 * A published version stays frozen: changing a price means publishing a
 * new version, never editing the published one (ADR-0071 §2). The
 * subscriptions already signed keep the terms they were signed on.
 */
final class UpdatePlanVersionController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly UpdatePlanVersion $updatePlanVersion,
    ) {}

    /**
     * Correct the terms of a draft version of a plan of the calling
     * Project.
     *
     * The version stays a draft: correcting it does not publish it. The
     * features are named by the project and typed by the Core (ADR-0071
     * §3), and the price is in minor units of a `char(3)` currency
     * (ADR-0071 §5).
     *
     * Requires the `plans:write` scope.
     *
     * @throws PlanNotFound the plan doesn't exist, or belongs to another project (404)
     * @throws PlanVersionNotFound the plan has no such version (404)
     * @throws PlanVersionNotEditable the version is published, and frozen (409)
     * @throws PlanVersionConflict the rank is already taken (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::PLAN_VERSION, description: 'The corrected draft version: it is still a draft.')]
    public function __invoke(string $code, string $version, UpdatePlanVersionRequest $request, ProjectContext $project): JsonResponse|Response
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

        /** @var array{price_minor: int, currency: string, interval_unit: string, interval_count: int, trial_days?: int|null, grace_days?: int|null, grace_access?: bool|null, features?: array<string, mixed>} $data */
        $data = $request->validated();

        $view = $this->updatePlanVersion->update(new UpdatePlanVersionCommand(
            projectId: $project->projectId,
            planCode: $code,
            version: (int) $version,
            priceMinor: $data['price_minor'],
            currency: $data['currency'],
            intervalUnit: $data['interval_unit'],
            intervalCount: $data['interval_count'],
            trialDays: $data['trial_days'] ?? 0,
            graceDays: $data['grace_days'] ?? 0,
            graceAccess: $data['grace_access'] ?? true,
            features: $data['features'] ?? [],
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->planVersionView($view));
    }
}
