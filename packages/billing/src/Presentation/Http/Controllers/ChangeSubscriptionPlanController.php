<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ChangeSubscriptionPlan;
use Core\Billing\Contracts\ChangeSubscriptionPlanCommand;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\ChangeSubscriptionPlanRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/subscriptions/{subscriptionId}/change-plan` — ADR-0072 §5.
 *
 * At the end of the period (the default), the next period is created
 * with the new version; immediately, the current period is closed now
 * and a new one starts. No prorata in v1 (ADR-0072 §5).
 */
final class ChangeSubscriptionPlanController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly ChangeSubscriptionPlan $changeSubscriptionPlan,
    ) {}

    /**
     * Change the plan of a subscription of the calling Project.
     *
     * `effective` is `period_end` — the default: the next period is
     * created with the new version, and the current one runs to its
     * end — or `immediate`: the current period is closed now and a new
     * one starts. No prorata in v1 (ADR-0072 §5).
     *
     * Requires the `subscriptions:write` scope.
     *
     * @throws SubscriptionNotFound the contract doesn't exist, or belongs to another project (404)
     * @throws PlanNotFound the plan doesn't exist, or belongs to another project (404)
     * @throws InvalidSubscriptionTransition the contract is already cancelled or expired (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::SUBSCRIPTION, description: 'The contract, with the new terms.')]
    public function __invoke(string $subscriptionId, ChangeSubscriptionPlanRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('subscriptions:write')) {
            return ProblemDetails::forbidden('The subscriptions:write scope is required.');
        }

        /** @var array{plan_code: string, effective?: string|null} $data */
        $data = $request->validated();

        $view = $this->changeSubscriptionPlan->changePlan(new ChangeSubscriptionPlanCommand(
            projectId: $project->projectId,
            subscriptionId: $subscriptionId,
            planCode: $data['plan_code'],
            effective: $data['effective'] ?? 'period_end',
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->subscriptionView($view));
    }
}
