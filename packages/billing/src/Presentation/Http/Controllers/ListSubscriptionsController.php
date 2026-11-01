<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionsCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Presentation\Http\Controllers\Concerns\ReadsListParameters;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/subscriptions?customer_ref=&status=&cursor=&limit=` —
 * ADR-0072 §7, the contracts of one project.
 */
final class ListSubscriptionsController
{
    use ReadsListParameters;
    use SerializesBillingViews;

    public function __construct(
        private readonly ListSubscriptions $listSubscriptions,
    ) {}

    /**
     * List the calling Project's subscriptions.
     *
     * The listing is paginated by an opaque cursor: pass `next_cursor`
     * back as `cursor` to read the next page, and `null` when there is
     * no more. `customer_ref` narrows it to one customer and `status`
     * to one status — `incomplete`, `trialing`, `active`, `past_due`,
     * `paused`, `canceled` or `expired`.
     *
     * Requires the `subscriptions:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::SUBSCRIPTION_PAGE, description: 'A page of contracts, and the cursor of the next one.')]
    public function __invoke(Request $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('subscriptions:read')) {
            return ProblemDetails::forbidden('The subscriptions:read scope is required.');
        }

        $page = $this->listSubscriptions->list(
            new ListSubscriptionsCommand(
                projectId: $project->projectId,
                customerRef: $this->stringQuery($request, 'customer_ref'),
                status: $this->stringQuery($request, 'status'),
                cursor: $this->listCursor($request),
                limit: $this->listLimit($request),
            ),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: the listing never crosses the projects.
            AccessScope::forProject($project->projectId),
        );

        return new JsonResponse([
            'data' => array_map(
                fn (SubscriptionView $view): array => $this->subscriptionView($view),
                $page->items,
            ),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
