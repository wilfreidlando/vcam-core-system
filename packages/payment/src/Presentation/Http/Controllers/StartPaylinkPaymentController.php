<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\PaymentFlow;
use Core\Payment\Presentation\Http\Requests\StartPaymentRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/payments/paylink` — starts a payment via MyCoolPay's hosted
 * payment link flow: the response's `payment_url` is where the customer
 * is redirected to complete payment. User request, in the same session
 * as `callback_url` (`AbstractStartPaymentController`): a redirect
 * (`return_url`) may never fire (closed tab, lost connection), so the
 * caller should also supply `callback_url` for the reliable,
 * server-to-server "this payment is done, go process it" notification.
 */
final class StartPaylinkPaymentController extends AbstractStartPaymentController
{
    /**
     * Start a payment via a hosted payment link.
     *
     * The request names only a `channel` (e.g. `mtn_momo`, `card`), never a
     * provider — `StartPaymentUseCase` resolves which adapter actually
     * handles it via the Project's routing configuration (ADR-0038 §2).
     * Requires the `payments:write` scope and an `Idempotency-Key` header
     * (G7, ADR-0025). `return_url` is a best-effort browser redirect
     * target only; supply `callback_url` for a reliable server-to-server
     * notification once the payment settles. Crediting a wallet on
     * success is never implicit — it only happens if
     * `wallet_recipient_external_ref` is given (ADR-0037).
     */
    public function __invoke(StartPaymentRequest $request, ProjectContext $project): JsonResponse|Response
    {
        return parent::__invoke($request, $project);
    }

    protected function flow(array $data): PaymentFlow
    {
        return PaymentFlow::Paylink;
    }
}
