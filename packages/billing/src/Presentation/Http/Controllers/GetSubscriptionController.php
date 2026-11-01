<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\GetSubscription;
use Core\Billing\Contracts\GetSubscriptionCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/subscriptions/{subscriptionId}` — ADR-0072 §7.
 *
 * The state of a contract, its current period and its next due date. A
 * contract of another project is simply not found — never told apart
 * from one that does not exist.
 */
final class GetSubscriptionController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly GetSubscription $getSubscription,
    ) {}

    /**
     * Get a subscription of the calling Project.
     *
     * Answers the contract, its current period and the date its next
     * period is due. Requires the `subscriptions:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::SUBSCRIPTION, description: 'The contract, its current period and its next due date.')]
    public function __invoke(string $subscriptionId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('subscriptions:read')) {
            return ProblemDetails::forbidden('The subscriptions:read scope is required.');
        }

        $view = $this->getSubscription->get(
            new GetSubscriptionCommand($project->projectId, $subscriptionId),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: a contract of another project is not found.
            AccessScope::forProject($project->projectId),
        );

        if ($view === null) {
            return ProblemDetails::make(
                'subscription-not-found',
                'Subscription not found',
                404,
                sprintf('No subscription carries the id "%s" in this project.', $subscriptionId),
            );
        }

        return new JsonResponse($this->subscriptionView($view));
    }
}
