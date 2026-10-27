<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/invoices/{invoiceId}` — ADR-0073 §1.
 *
 * The document, its lines and what has been paid of it. An invoice of
 * another project is simply not found — never told apart from one that
 * does not exist.
 */
final class GetInvoiceController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly GetInvoice $getInvoice,
    ) {}

    /**
     * Get an invoice of the calling Project.
     *
     * Answers the document, its lines, its totals and what has been
     * paid of it. Requires the `invoices:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::INVOICE, description: 'The invoice, its lines and what has been paid of it.')]
    public function __invoke(string $invoiceId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('invoices:read')) {
            return ProblemDetails::forbidden('The invoices:read scope is required.');
        }

        $view = $this->getInvoice->get(
            new GetInvoiceCommand($project->projectId, $invoiceId),
            // ADR-0069 §2 — the public API plane's scope is the calling
            // project itself: an invoice of another project is not found.
            AccessScope::forProject($project->projectId),
        );

        if ($view === null) {
            return ProblemDetails::make(
                'invoice-not-found',
                'Invoice not found',
                404,
                sprintf('No invoice carries the id "%s" in this project.', $invoiceId),
            );
        }

        return new JsonResponse($this->invoiceView($view));
    }
}
