<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Domain\Exception\InvalidBillingInterval;
use Core\Billing\Domain\Exception\InvalidPlanFeature;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\UnsupportedCurrency;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\CreatePlanVersionRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/plans/{code}/versions` — ADR-0071 §2.
 *
 * The terms of a plan. Changing a price means publishing a **new**
 * version: a published one is immutable (ADR-0071 §2), and the
 * subscriptions already signed keep the terms they were signed on.
 */
final class CreatePlanVersionController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly CreatePlanVersion $createPlanVersion,
    ) {}

    /**
     * Add a version to a plan of the calling Project.
     *
     * The version is created `draft`: it becomes the one new
     * subscriptions take only once it is published (ADR-0071 §2). The
     * price is in minor units and the currency is a `char(3)` — one
     * currency per version, no conversion in the Core (ADR-0071 §5).
     *
     * The features are named by the project and typed by the Core: their
     * keys are format-checked, never listed by the Core (ADR-0071 §3).
     *
     * Requires the `plans:write` scope.
     *
     * @throws PlanNotFound the plan doesn't exist, or belongs to another project (404)
     * @throws InvalidBillingInterval the interval count is below one (422)
     * @throws InvalidPlanFeature a feature key or definition is not one the Core accepts (422)
     * @throws UnsupportedCurrency the currency is not one the ledger supports (422)
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::PLAN_VERSION, description: 'The draft version, with the terms of the plan.')]
    public function __invoke(string $code, CreatePlanVersionRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('plans:write')) {
            return ProblemDetails::forbidden('The plans:write scope is required.');
        }

        /** @var array{price_minor: int, currency: string, interval_unit: string, interval_count: int, trial_days?: int|null, grace_days?: int|null, grace_access?: bool|null, features?: array<string, mixed>} $data */
        $data = $request->validated();

        $view = $this->createPlanVersion->createVersion(new CreatePlanVersionCommand(
            projectId: $project->projectId,
            planCode: $code,
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

        return new JsonResponse($this->planVersionView($view), 201);
    }
}
