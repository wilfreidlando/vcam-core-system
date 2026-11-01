<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\PayInvoice;
use Core\Billing\Contracts\PayInvoiceCommand;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\PayInvoiceRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\PaymentChannel;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/invoices/{invoiceId}/pay` — ADR-0073 §2, ADR-0094 §8.
 *
 * The online collection of an invoice: the Core starts the payment on
 * the rail the caller chose, with the intention `invoice`, and records
 * the attempt that will carry the money onto the receivable once the
 * payment settles.
 *
 * The route carries the `idempotency` middleware (G7): the same
 * `Idempotency-Key` is handed to the `payment` module, which never
 * charges the customer twice for the same key.
 */
final class PayInvoiceController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly PayInvoice $payInvoice,
    ) {}

    /**
     * Pay an invoice of the calling Project.
     *
     * Answers the attempt, and what the payer must do next: the
     * provider's own action (a URL to open, an instruction to follow).
     * The invoice is credited once the payment settles — not before.
     *
     * Requires the `invoices:write` scope.
     *
     * @throws \Core\Billing\Domain\Exception\InvoiceNotFound no invoice carries this id in this project (404)
     * @throws \Core\Billing\Domain\Exception\InvoiceNotPayable the invoice is not due (409)
     */
    #[ScrambleResponse(status: 201, type: BillingResponses::PAY_INVOICE, description: 'The collection attempt, and what the payer must do next.')]
    public function __invoke(PayInvoiceRequest $request, string $invoiceId, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('invoices:write')) {
            return ProblemDetails::forbidden('The invoices:write scope is required.');
        }

        $idempotencyKey = $request->header(RequireIdempotencyKey::HEADER);

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            // Unreachable in practice — the `idempotency` middleware
            // already rejected the request otherwise — but the type
            // system doesn't know that, and failing loudly here is
            // cheaper than trusting it silently.
            throw new LogicException(self::class.' reached without a validated Idempotency-Key header.');
        }

        /** @var array{channel: string, customer_phone_number?: string, customer_name?: string, customer_email?: string, customer_lang?: string, return_url?: string} $data */
        $data = $request->validated();

        $result = $this->payInvoice->pay(new PayInvoiceCommand(
            projectId: $project->projectId,
            invoiceId: $invoiceId,
            channel: PaymentChannel::fromString($data['channel']),
            idempotencyKey: $idempotencyKey,
            customerPhoneNumber: $data['customer_phone_number'] ?? null,
            customerName: $data['customer_name'] ?? null,
            customerEmail: $data['customer_email'] ?? null,
            customerLang: $data['customer_lang'] ?? null,
            returnUrl: $data['return_url'] ?? null,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        return new JsonResponse([
            'payment' => $this->invoicePaymentView($result->payment),
            'requires_action' => $result->requiresAction,
            'payment_url' => $result->paymentUrl,
            'action_hint' => $result->actionHint,
        ], 201);
    }
}
