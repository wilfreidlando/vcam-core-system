<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\CancelSubscription;
use Core\Billing\Contracts\CancelSubscriptionCommand;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\CancelSubscriptionRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/subscriptions/{subscriptionId}/cancel` — ADR-0072 §7.
 *
 * `canceled` and `expired` are final states (ADR-0072 §2): a cancelled
 * contract never comes back — a new one is signed instead.
 */
final class CancelSubscriptionController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly CancelSubscription $cancelSubscription,
    ) {}

    /**
     * Cancel a subscription of the calling Project.
     *
     * `at_period_end` cancels at the end of the current period — the
     * default, and what the customer expects: it keeps its rights until
     * then. `false` cancels immediately and what is left of the period
     * is lost.
     *
     * Requires the `subscriptions:write` scope.
     *
     * @throws SubscriptionNotFound the contract doesn't exist, or belongs to another project (404)
     * @throws InvalidSubscriptionTransition the contract is already cancelled or expired (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::SUBSCRIPTION, description: 'The cancelled contract.')]
    public function __invoke(string $subscriptionId, CancelSubscriptionRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('subscriptions:write')) {
            return ProblemDetails::forbidden('The subscriptions:write scope is required.');
        }

        /** @var array{at_period_end?: bool|null, reason?: string|null} $data */
        $data = $request->validated();

        $view = $this->cancelSubscription->cancel(new CancelSubscriptionCommand(
            projectId: $project->projectId,
            subscriptionId: $subscriptionId,
            atPeriodEnd: $data['at_period_end'] ?? true,
            reason: $data['reason'] ?? null,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->subscriptionView($view));
    }
}
