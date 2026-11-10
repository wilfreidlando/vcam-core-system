<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ListSubscriptionSupplements;
use Core\Billing\Contracts\ListSubscriptionSupplementsCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/subscriptions/{id}/supplements` — ADR-0117 §2.
 *
 * What was bought on top of the plan of the contract, and what is still
 * waiting for its payment: a supplement is `pending` until its invoice
 * is paid, and only an `active` one grants its allowance.
 */
final class ListSubscriptionSupplementsController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly ListSubscriptionSupplements $list,
    ) {}

    /**
     * Read the supplements of a contract.
     *
     * Requires the `subscriptions:read` scope. A contract of another
     * project is answered like a missing one (G15): the list is empty.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::SUPPLEMENT_PAGE, description: 'The supplements of the contract, and the invoices that carry them.')]
    public function __invoke(string $subscriptionId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('subscriptions:read')) {
            return ProblemDetails::forbidden('The subscriptions:read scope is required.');
        }

        $views = $this->list->list(
            new ListSubscriptionSupplementsCommand($project->projectId, $subscriptionId),
            AccessScope::forProject($project->projectId),
        );

        return new JsonResponse([
            'data' => array_map($this->supplementView(...), $views),
        ]);
    }
}
