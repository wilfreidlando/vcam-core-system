<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\PaymentFlow;
use Core\Payment\Presentation\Http\Requests\StartPaymentRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/payments/payin` — starts a payment via MyCoolPay's direct,
 * app-driven flow (e.g. a USSD push to the customer's phone) — no
 * hosted link, no browser redirect at all. User request: `callback_url`
 * is how the caller learns the payment settled — there is no
 * `return_url` to fall back on for this flow, so the reliable
 * server-to-server notification matters even more here.
 */
final class StartPayinPaymentController extends AbstractStartPaymentController
{
    /**
     * Start a payment via a direct, app-driven charge.
     *
     * The request names only a `channel` (e.g. `mtn_momo`, `card`), never a
     * provider — `StartPaymentUseCase` resolves which adapter actually
     * handles it via the Project's routing configuration (ADR-0038 §2).
     * Requires the `payments:write` scope and an `Idempotency-Key` header
     * (G7, ADR-0025). There is no browser redirect for this flow — supply
     * `callback_url` to be notified once the payment settles. Crediting a
     * wallet on success is never implicit — it only happens if
     * `wallet_recipient_external_ref` is given (ADR-0037).
     */
    public function __invoke(StartPaymentRequest $request, ProjectContext $project): JsonResponse|Response
    {
        return parent::__invoke($request, $project);
    }

    protected function flow(array $data): PaymentFlow
    {
        return PaymentFlow::Payin;
    }
}
