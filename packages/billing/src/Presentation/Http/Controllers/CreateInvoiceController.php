<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\CreateInvoiceRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/invoices` — ADR-0073 §1, a manual invoice.
 *
 * The invoice is created **draft**: it has no number yet. The number is
 * attributed at the issuance, under the lock of the sequence of the
 * project (ADR-0094 §5) — a draft can be corrected, an issued invoice
 * cannot.
 */
final class CreateInvoiceController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly CreateInvoice $createInvoice,
    ) {}

    /**
     * Create a manual invoice for a customer of the calling Project.
     *
     * Answers the draft invoice, its lines and the totals it carries.
     * Requires the `invoices:write` scope.
     *
     * The amounts are in **minor units** and the tax is a rate, never a
     * tax regime: the Core computes what it is given, and rounds it half
     * up on each line (ADR-0094 §1).
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::INVOICE, description: 'The draft invoice, its lines and its totals.')]
    public function __invoke(CreateInvoiceRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('invoices:write')) {
            return ProblemDetails::forbidden('The invoices:write scope is required.');
        }

        /** @var array{customer_ref: string, currency?: string|null, tax_rate_bps?: int|null, due_at?: string|null, lines: list<array{description: string, quantity: int, unit_amount_minor: int, tax_rate_bps?: int|null}>} $data */
        $data = $request->validated();

        $lines = array_map(
            static fn (array $line): InvoiceLineInput => new InvoiceLineInput(
                description: $line['description'],
                quantity: $line['quantity'],
                unitAmountMinor: $line['unit_amount_minor'],
                taxRateBps: $line['tax_rate_bps'] ?? null,
            ),
            $data['lines'],
        );

        $view = $this->createInvoice->create(new CreateInvoiceCommand(
            projectId: $project->projectId,
            customerRef: $data['customer_ref'],
            lines: $lines,
            dueAt: $data['due_at'] ?? null,
            currency: $data['currency'] ?? null,
            taxRateBps: $data['tax_rate_bps'] ?? null,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse($this->invoiceView($view), 201);
    }
}
