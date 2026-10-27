<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/invoices/{invoiceId}/issue` — ADR-0073 §1.
 *
 * The draft becomes a document: the number is attributed under the lock
 * of the sequence of the project, the receivable is written to the
 * ledger, and the invoice is frozen.
 */
final class IssueInvoiceController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly IssueInvoice $issueInvoice,
    ) {}

    /**
     * Issue an invoice of the calling Project.
     *
     * Answers the issued invoice, with its number and the instant it was
     * issued. From this instant the document is frozen: only its status
     * and what has been paid of it move.
     *
     * Requires the `invoices:write` scope.
     *
     * @throws \Core\Billing\Domain\Exception\InvoiceNotFound no invoice carries this id in this project (404)
     * @throws \Core\Billing\Domain\Exception\InvalidInvoiceTransition the invoice is not a draft any more (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::INVOICE, description: 'The issued invoice, with its number.')]
    public function __invoke(string $invoiceId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('invoices:write')) {
            return ProblemDetails::forbidden('The invoices:write scope is required.');
        }

        $view = $this->issueInvoice->issue(new IssueInvoiceCommand(
            projectId: $project->projectId,
            invoiceId: $invoiceId,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->invoiceView($view));
    }
}
