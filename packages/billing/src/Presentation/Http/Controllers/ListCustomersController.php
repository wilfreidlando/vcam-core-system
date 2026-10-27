<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\ListCustomers;
use Core\Billing\Contracts\ListCustomersCommand;
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
 * `GET /v1/customers?cursor=&limit=&search=` — ADR-0070 §2, the
 * cursor-paginated listing of a project's customers (ADR-0088 §2).
 */
final class ListCustomersController
{
    use ReadsListParameters;
    use SerializesBillingViews;

    public function __construct(
        private readonly ListCustomers $listCustomers,
    ) {}

    /**
     * List the calling Project's customers.
     *
     * The listing is paginated by an opaque cursor: pass `next_cursor`
     * back as `cursor` to read the next page, and `null` when there is
     * no more. `search` filters on the external reference and the
     * display name — the two fields the project knows a customer by.
     *
     * Requires the `customers:read` scope — the only scope that carries
     * the email and the phone out of the Core (ADR-0070 §Sécurité).
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::CUSTOMER_PAGE, description: 'A page of customers, and the cursor of the next one.')]
    public function __invoke(Request $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('customers:read')) {
            return ProblemDetails::forbidden('The customers:read scope is required.');
        }

        $page = $this->listCustomers->list(
            new ListCustomersCommand(
                projectId: $project->projectId,
                cursor: $this->listCursor($request),
                limit: $this->listLimit($request),
                search: $this->stringQuery($request, 'search'),
            ),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: the listing never crosses the projects.
            AccessScope::forProject($project->projectId),
        );

        return new JsonResponse([
            'data' => array_map(
                fn (CustomerView $view): array => $this->customerView($view, true),
                $page->items,
            ),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
