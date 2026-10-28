<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\InvoicePaymentView;
use Core\Billing\Contracts\ListInvoicePayments;
use Core\Billing\Contracts\ListInvoicePaymentsCommand;
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
 * `GET /v1/invoices/{invoiceId}/payments` — ADR-0073 §2, the attempts
 * to collect one invoice.
 *
 * Every attempt is a row, not a column: a failed one, a canceled one
 * and a second one that succeeds are all there, and a payment that
 * lands late finds its own row (ADR-0073 §Contexte).
 */
final class ListInvoicePaymentsController
{
    use ReadsListParameters;
    use SerializesBillingViews;

    public function __construct(
        private readonly ListInvoicePayments $listInvoicePayments,
    ) {}

    /**
     * List the collection attempts of one invoice of the calling
     * Project.
     *
     * The listing is paginated by an opaque cursor: pass
     * `next_cursor` back as `cursor` to read the next page, and `null`
     * when there is no more.
     *
     * Requires the `invoices:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::INVOICE_PAYMENT_PAGE, description: 'A page of collection attempts, and the cursor of the next one.')]
    public function __invoke(Request $request, string $invoiceId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('invoices:read')) {
            return ProblemDetails::forbidden('The invoices:read scope is required.');
        }

        $page = $this->listInvoicePayments->list(
            new ListInvoicePaymentsCommand(
                projectId: $project->projectId,
                invoiceId: $invoiceId,
                cursor: $this->listCursor($request),
                limit: $this->listLimit($request),
            ),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: the listing never crosses the projects.
            AccessScope::forProject($project->projectId),
        );

        return new JsonResponse([
            'data' => array_map(
                fn (InvoicePaymentView $view): array => $this->invoicePaymentView($view),
                $page->items,
            ),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
