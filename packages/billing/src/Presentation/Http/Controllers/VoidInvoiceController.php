<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\VoidInvoice;
use Core\Billing\Contracts\VoidInvoiceCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\VoidInvoiceRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/invoices/{invoiceId}/void` — ADR-0073 §3.
 *
 * The invoice is cancelled before any payment: the issuance is
 * counter-passed and the document keeps its number.
 */
final class VoidInvoiceController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly VoidInvoice $voidInvoice,
    ) {}

    /**
     * Void an invoice of the calling Project.
     *
     * Answers the cancelled invoice, with the motive of the
     * cancellation. An invoice that has already been paid is **refused**
     * (`409`): the money would have to be given back, and that is a
     * refund, not a void.
     *
     * Requires the `invoices:write` scope.
     *
     * @throws \Core\Billing\Domain\Exception\InvoiceNotFound no invoice carries this id in this project (404)
     * @throws \Core\Billing\Domain\Exception\InvalidInvoiceTransition the invoice is not open, or something has been paid on it (409)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::INVOICE, description: 'The cancelled invoice, with the motive of the cancellation.')]
    public function __invoke(string $invoiceId, VoidInvoiceRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('invoices:write')) {
            return ProblemDetails::forbidden('The invoices:write scope is required.');
        }

        /** @var array{reason: string} $data */
        $data = $request->validated();

        $view = $this->voidInvoice->void(new VoidInvoiceCommand(
            projectId: $project->projectId,
            invoiceId: $invoiceId,
            reason: $data['reason'],
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->invoiceView($view));
    }
}
