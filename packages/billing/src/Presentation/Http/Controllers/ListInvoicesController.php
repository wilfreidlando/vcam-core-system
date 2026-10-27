<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListInvoicesCommand;
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
 * `GET /v1/invoices?customer_ref=&status=&cursor=&limit=` —
 * ADR-0073 §1, the invoices of one project.
 */
final class ListInvoicesController
{
    use ReadsListParameters;
    use SerializesBillingViews;

    public function __construct(
        private readonly ListInvoices $listInvoices,
    ) {}

    /**
     * List the calling Project's invoices.
     *
     * The listing is paginated by an opaque cursor: pass `next_cursor`
     * back as `cursor` to read the next page, and `null` when there is
     * no more. `customer_ref` narrows it to one customer and `status`
     * to one status — `draft`, `open`, `paid`, `void` or
     * `uncollectible`.
     *
     * Requires the `invoices:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::INVOICE_PAGE, description: 'A page of invoices, and the cursor of the next one.')]
    public function __invoke(Request $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('invoices:read')) {
            return ProblemDetails::forbidden('The invoices:read scope is required.');
        }

        $page = $this->listInvoices->list(
            new ListInvoicesCommand(
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
                fn (InvoiceView $view): array => $this->invoiceView($view),
                $page->items,
            ),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
